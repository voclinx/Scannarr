# Scanarr — Chaîne de Suppression

> **Prérequis** : [DATABASE.md](DATABASE.md), [API.md](API.md), [WATCHER.md](WATCHER.md)
> **Version** : V1.2.1

Couvre : suppression immédiate, suppression planifiée, ScheduledDeletion éphémère, communication watcher, reconnexion, notifications Discord.

---

## 10. Suppression planifiée

### 10.1 Flux complet

```
1. Utilisateur crée une suppression planifiée via POST /api/v1/scheduled-deletions
   → Status : "pending"
   → Items enregistrés avec leurs media_file_ids

2. Cron quotidien (SendDeletionRemindersCommand) vérifie chaque jour :
   → Pour chaque deletion "pending" dont scheduled_date - reminder_days_before <= aujourd'hui
   → Envoyer notification Discord "Rappel : X films seront supprimés le DD/MM/YYYY"
   → Status → "reminder_sent"

3. Cron quotidien (ProcessScheduledDeletionsCommand) vérifie chaque jour à 23h55 :
   → Pour chaque deletion dont scheduled_date == aujourd'hui et status in ("pending", "reminder_sent")
   → Status → "executing"

   Phase 1 — API (actions HTTP, exécutées côté API) :
     Pour chaque item :
       a. Si delete_radarr_reference : appeler l'API Radarr pour déréférencer le film
          (AVANT toute suppression physique, empêche le re-téléchargement)
          addExclusion = false (le re-téléchargement doit rester possible)
       b. Sinon si disable_radarr_auto_search : désactiver le monitoring Radarr (monitored=false)
       c. Pour chaque fichier de l'item : nettoyage qBittorrent (best-effort)
          → chercher le torrent via content_path, le supprimer (deleteFiles=false)

   Phase 2 — Watcher (actions filesystem, déléguées via WebSocket) :
     d. Envoyer la commande command.files.delete au watcher via WebSocket
        (payload : deletion_id + request_id + liste [{media_file_id, volume_path, file_path}])
     e. Si le watcher est offline :
        → ScheduledDeletion.status = "waiting_watcher"
        → La commande sera renvoyée automatiquement à la reconnexion du watcher

   Phase 3 — Watcher traite (sur le serveur des fichiers) :
     f. Pour chaque fichier : unlink() le fichier physique
     g. Pour chaque fichier supprimé : nettoyer les dossiers parents vides
        (remonter récursivement jusqu'au volume_path, ne jamais supprimer le root)
     h. Renvoyer files.delete.progress pour chaque fichier (succès ou échec)
     i. Renvoyer files.delete.completed quand tous traités

   Phase 4 — API (à réception de files.delete.completed via WatcherMessageProcessor) :
     j. Pour chaque fichier supprimé : supprimer l'entrée media_file en BDD
     k. Refresh bibliothèques Plex/Jellyfin (API HTTP, best-effort)
     l. Générer le execution_report (JSON)
     m. Status → "completed" (ou "failed" si TOUS les items ont échoué)
     n. Envoyer notification Discord de confirmation

4. L'utilisateur peut annuler à tout moment avant l'exécution :
   → DELETE /api/v1/scheduled-deletions/{id}
   → Status → "cancelled"
```

**Architecture de la suppression — Qui fait quoi** :

```
┌──────────────────────────────────┐     ┌──────────────────────────────┐
│         API (serveur A)          │     │     Watcher (serveur B)      │
│                                  │     │                              │
│  1. Radarr dereference (HTTP)    │     │                              │
│  2. qBittorrent cleanup (HTTP)   │     │                              │
│  3. Envoie command.files.delete ─┼────►│  4. unlink() fichiers        │
│                                  │     │  5. rmdir() dossiers vides   │
│  7. Supprime media_files en BDD ◄┼─────┤  6. Renvoie résultats        │
│  8. Refresh Plex/Jellyfin (HTTP) │     │                              │
│  9. Discord notification (HTTP)  │     │                              │
└──────────────────────────────────┘     └──────────────────────────────┘
```

**Règle fondamentale** : L'API ne fait JAMAIS de unlink() ou rmdir(). Toute opération filesystem passe par le watcher via WebSocket.

**Paths** : Le watcher utilise `volume.hostPath` (chemin sur le serveur B, avec fallback sur `volume.path`). La commande envoyée au watcher contient `volume_path` (racine du volume) et `file_path` (chemin relatif), que le watcher reconstruit en chemin absolu via `filepath.Join(volume_path, file_path)`.

**Status `waiting_watcher`** : Si le watcher est offline au moment de l'exécution, la suppression est mise en attente. Dès que le watcher se reconnecte (auth WebSocket réussie), l'API renvoie automatiquement les commandes en attente.

**Messages WebSocket — Suppression de fichiers** :

| Direction | Type | Description |
|-----------|------|-------------|
| API → Watcher | `command.files.delete` | Commande avec deletion_id + request_id + liste de fichiers (volume_path, file_path, media_file_id) |
| Watcher → API | `files.delete.progress` | Résultat par fichier (status deleted/failed, dirs_removed, error) |
| Watcher → API | `files.delete.completed` | Résumé final (total, deleted, failed, results[]) |

**Corrélation async via `ScheduledDeletion`** : Chaque commande `command.files.delete` contient un `deletion_id` qui correspond à l'UUID d'une `ScheduledDeletion` en BDD. Quand le watcher renvoie `files.delete.completed` avec ce même `deletion_id`, le `WatcherMessageProcessor` retrouve la `ScheduledDeletion` et effectue la finalisation (cleanup BDD, refresh media players, Discord).

Pour les suppressions en `WAITING_WATCHER` (watcher offline), la méthode `getPendingDeletionCommands()` reconstruit les commandes à partir des `ScheduledDeletion` en attente et les renvoie automatiquement à la reconnexion du watcher.

### 10.2 Commandes Symfony

```php
// ProcessScheduledDeletionsCommand.php
// Exécution : tous les jours à 23:55 via cron
// bin/console scanarr:process-deletions

// SendDeletionRemindersCommand.php
// Exécution : tous les jours à 09:00 via cron
// bin/console scanarr:send-reminders
```

### 10.3 Crontab Docker

```crontab
55 23 * * * /usr/local/bin/php /app/bin/console scanarr:process-deletions >> /var/log/scanarr/deletions.log 2>&1
0  9  * * * /usr/local/bin/php /app/bin/console scanarr:send-reminders >> /var/log/scanarr/reminders.log 2>&1
```

---

## 11. Notifications Discord

### 11.1 Format des messages

**Rappel avant suppression :**

```json
// POST vers discord_webhook_url
{
  "embeds": [
    {
      "title": "⚠️ Rappel — Suppression planifiée",
      "description": "**3 films** seront supprimés le **10/08/2026 à 23:59**.",
      "color": 16744448,
      "fields": [
        { "name": "Films concernés", "value": "• Inception (2010)\n• The Matrix (1999)\n• Avatar (2009)", "inline": false },
        { "name": "Fichiers à supprimer", "value": "5 fichiers (120 Go)", "inline": true },
        { "name": "Créé par", "value": "admin", "inline": true }
      ],
      "footer": { "text": "Scanarr — Annulez via l'interface si besoin" },
      "timestamp": "2026-08-07T09:00:00Z"
    }
  ]
}
```

**Confirmation après suppression :**

```json
{
  "embeds": [
    {
      "title": "✅ Suppression exécutée",
      "description": "**3 films** ont été supprimés avec succès.",
      "color": 3066993,
      "fields": [
        { "name": "Films supprimés", "value": "• Inception (2010) ✅\n• The Matrix (1999) ✅\n• Avatar (2009) ✅", "inline": false },
        { "name": "Espace libéré", "value": "120 Go", "inline": true },
        { "name": "Radarr déréférencé", "value": "Oui", "inline": true }
      ],
      "footer": { "text": "Scanarr" },
      "timestamp": "2026-08-10T23:59:00Z"
    }
  ]
}
```

**Rapport d'erreurs :**

```json
{
  "embeds": [
    {
      "title": "❌ Suppression — Erreurs détectées",
      "description": "La suppression planifiée du **10/08/2026** a rencontré des erreurs.",
      "color": 15158332,
      "fields": [
        { "name": "Succès", "value": "• Inception (2010) ✅\n• The Matrix (1999) ✅", "inline": false },
        { "name": "Échecs", "value": "• Avatar (2009) ❌ — Permission denied on /mnt/nas/...", "inline": false }
      ],
      "footer": { "text": "Scanarr — Vérifiez les permissions de fichiers" },
      "timestamp": "2026-08-10T23:59:00Z"
    }
  ]
}
```

---

