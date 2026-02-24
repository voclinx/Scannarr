# CLAUDE.md — Instructions pour le projet Scanarr

## Contexte

Tu travailles sur **Scanarr**, une application de gestion et surveillance de bibliothèque multimédia. Le cahier des charges complet et la spécification technique détaillée se trouvent dans le fichier `STD_Scanarr V1.1.md` à la racine de ce répertoire. **Lis-le intégralement avant de commencer quoi que ce soit.**

## Règle absolue

**Avant chaque phase de développement, relis la section correspondante de la STD.** Ne code jamais de mémoire — retourne toujours vérifier les spécifications (schéma BDD, format des endpoints, types TypeScript, messages WebSocket, etc.) dans le document.

## Architecture

Le projet est un **monorepo** composé de 3 briques :

| Dossier | Stack | Rôle |
|---------|-------|------|
| `api/` | Symfony 7 / PHP 8.3 / Doctrine / JWT | Back-end API REST + serveur WebSocket |
| `front/` | Vue.js 3 / TypeScript / Vite / Pinia / PrimeVue / Tailwind | Front-end SPA |
| `watcher/` | Go 1.22+ / fsnotify / gorilla/websocket | Binaire natif (hors Docker) — surveillance filesystem |

Base de données : **PostgreSQL 16** (dans Docker).

Le watcher **ne tourne PAS dans Docker**. C'est un binaire Go natif installé sur le serveur hôte avec un service systemd. Voir la section 12.5 de la STD.

## Ordre d'implémentation

Suis **strictement** l'ordre défini dans la section 14 de la STD. Les 5 phases sont :

### Phase 1 — Fondations
1. Structure du monorepo (dossiers, docker-compose, .env.example)
2. Docker compose : PostgreSQL + API Symfony + Front Vue.js
3. Symfony : installation, Doctrine, JWT (lexik/jwt-authentication-bundle), security
4. Migrations BDD : **toutes les tables** définies en section 4 de la STD (users, volumes, media_files, movies, movie_files, radarr_instances, media_player_instances, scheduled_deletions, scheduled_deletion_items, settings, activity_logs)
5. Auth : setup wizard, login, logout, refresh token, endpoint /me
6. CRUD users (admin only)
7. Vue.js : installation avec TypeScript, Vue Router, Pinia, Axios, PrimeVue 4, Tailwind CSS 3
8. Front : LoginView, SetupWizardView, AppLayout (sidebar + header), auth store, route guards

### Phase 2 — Watcher + Explorateur
9. Go watcher : structure projet, config (env vars), client WebSocket avec reconnexion auto
10. Go watcher : module fsnotify (watch mode) avec filtrage fichiers
11. Go watcher : module scanner (scan récursif)
12. Go watcher : calcul hardlinks, install.sh, fichier systemd, watcher.env.example
13. Symfony : serveur WebSocket Ratchet (port 8081), auth watcher par token
14. Symfony : WatcherMessageHandler — traitement de tous les types de messages (file.created, file.deleted, file.renamed, file.modified, scan.*)
15. Symfony : Volume CRUD + endpoint POST /volumes/{id}/scan
16. Symfony : File listing avec recherche, filtres, pagination
17. Front : FileExplorerView, FileTable, sélecteur de volumes
18. Front : FileDeleteModal (suppression simple + option Radarr)

### Phase 3 — Films + Intégrations
19. Symfony : RadarrService, TmdbService
20. Symfony : Radarr instance CRUD + test connexion
21. Symfony : SyncRadarrCommand (import films + enrichissement TMDB)
22. Symfony : MovieMatcherService (liaison fichiers ↔ films par API Radarr + parsing nom de fichier)
23. Symfony : Movie listing, détail, recherche/filtres/tri
24. Symfony : Movie deletion globale (choix à la carte)
25. Symfony : PlexService, JellyfinService (test connexion + détection de présence)
26. Symfony : MediaPlayer CRUD
27. Front : MoviesListView, MovieTable
28. Front : MovieDetailView, MovieFileList, MovieGlobalDeleteModal
29. Front : SettingsView avec onglets (Radarr, Lecteurs, Volumes, Torrent)

### Phase 4 — Suppression planifiée + Notifications
30. Symfony : ScheduledDeletion CRUD
31. Symfony : DeletionService (logique de suppression physique + déréférencement)
32. Symfony : ProcessScheduledDeletionsCommand (cron quotidien 23h55)
33. Symfony : DiscordNotificationService (webhooks embeds)
34. Symfony : SendDeletionRemindersCommand (cron quotidien 09h00)
35. Front : ScheduledDeletionsView + formulaire de création
36. Front : ScheduledDeletionList (liste avec statuts visuels)
37. Front : Intégration suppression planifiée depuis MovieDetailView
38. Front : SettingsView — onglet Discord

### Phase 5 — Dashboard + Polish
39. Symfony : DashboardController (stats agrégées)
40. Symfony : ActivityLog listener Doctrine
41. Front : DashboardView (stats cards, activité récente)
42. Front : UsersManagementView
43. Tests back-end (PHPUnit) — voir section 13.1 de la STD
44. Tests front-end (Vitest) — voir section 13.2 de la STD
45. Tests Go — voir section 13.3 de la STD
46. Tests d'intégration — voir section 13.4 de la STD
47. Docker : finaliser Dockerfiles prod (API + Front)
48. Watcher : finaliser install.sh + systemd
49. README.md avec instructions d'installation complètes

## Conventions de code

### PHP / Symfony
- PSR-12 pour le style de code
- Nommage : PascalCase pour les classes, camelCase pour les méthodes et variables
- Chaque entité Doctrine dans `src/Entity/`, chaque repository dans `src/Repository/`
- Services dans `src/Service/`, commandes CLI dans `src/Command/`
- Enums PHP 8.1+ dans `src/Enum/` (UserRole, VolumeType, VolumeStatus, FileEventType, DeletionStatus)
- Utilise les attributs PHP 8 pour Doctrine (`#[ORM\Entity]`, `#[ORM\Column]`, etc.)
- Utilise les attributs Symfony pour le routing (`#[Route]`)
- Validation par attributs Symfony Validator (`#[Assert\NotBlank]`, etc.)
- Format de réponse API : toujours `{ "data": ... , "meta": ... }` ou `{ "error": ... }` — voir section 5.2 de la STD
- UUID pour tous les identifiants (pas d'auto-increment)

### TypeScript / Vue.js
- Composition API avec `<script setup lang="ts">` systématiquement
- Types définis dans `src/types/index.ts` — voir section 6.2 de la STD
- Stores Pinia dans `src/stores/` — un store par domaine (auth, volumes, files, movies, settings, notifications)
- Composables dans `src/composables/` (useApi, useAuth, useConfirmation)
- Axios avec interceptor JWT refresh automatique sur 401
- PrimeVue DataTable pour tous les tableaux avec pagination serveur
- Tailwind pour le layout et l'espacement, PrimeVue pour les composants interactifs
- Pas d'utilisation de `any` — typer tout explicitement

### Go
- Structure standard Go avec `cmd/`, `internal/`
- Packages internes : `config`, `watcher`, `scanner`, `websocket`, `models`
- Structured logging avec `log/slog`
- Gestion d'erreurs explicite (pas de panic en prod)
- Tests dans des fichiers `_test.go` à côté du code

### Base de données
- UUID partout (pas de SERIAL/auto-increment)
- Timestamps : `created_at` et `updated_at` sur toutes les tables
- Index sur les colonnes fréquemment filtrées (voir section 4 de la STD)
- Relations avec ON DELETE CASCADE ou SET NULL selon la STD
- Les champs JSONB sont utilisés pour les données flexibles (root_folders, execution_report, media_file_ids)

## Points d'attention critiques

### Correspondance chemins Watcher ↔ API Docker
La table `volumes` a deux champs de chemin :
- `path` : chemin vu par l'API dans Docker (ex: `/mnt/volume1`)
- `host_path` : chemin réel sur le serveur hôte (ex: `/mnt/media1`)

Le watcher envoie des chemins `host_path`. Le `WatcherMessageHandler` doit traduire les préfixes pour retrouver le bon volume en BDD. Voir section 7.7 de la STD.

### Suppression de fichiers — Sécurité
- Toute suppression physique de fichier (`unlink`) doit être loguée dans `activity_logs`
- Toute suppression nécessite une confirmation côté front (modale)
- Vérifier les permissions du rôle côté back **avant** toute suppression (Voters Symfony)
- La suppression globale doit avertir si Radarr a la recherche automatique activée

### WebSocket Watcher
- Le watcher s'authentifie avec un message `type: "auth"` contenant le token partagé
- Reconnexion automatique avec backoff exponentiel côté Go
- Ping/Pong toutes les 30s pour détecter les déconnexions
- Tous les messages en JSON — voir formats exacts en section 7.2 et 7.3 de la STD

### Suppression planifiée
- Exécution automatique à 23:55 via cron Symfony
- Rappel Discord configurable X jours avant (cron à 09:00)
- Les suppressions doivent survivre à un redémarrage (persistées en BDD)
- Un fichier déjà supprimé manuellement = item en status "failed" avec message d'erreur

## Ce qu'il ne faut PAS faire

- Ne pas inventer de endpoints ou de tables qui ne sont pas dans la STD
- Ne pas utiliser d'auto-increment — UUID partout
- Ne pas mettre le watcher dans Docker
- Ne pas utiliser `WidthType.PERCENTAGE` pour les tables (si génération docx)
- Ne pas stocker les mots de passe en clair — bcrypt ou Argon2
- Ne pas oublier les index sur les colonnes filtrées
- Ne pas hardcoder les URLs des services externes — tout passe par la config/settings
- Ne pas créer de fichiers de migration manuellement — utiliser `doctrine:migrations:diff`
- Ne pas implémenter les fonctionnalités V2/V3 (séries, cross-seed, notifications avancées) — on fait uniquement la V1

## Comment travailler

1. **Commence par la Phase 1** : structure du projet, Docker, BDD, Auth
2. **À chaque étape**, relis la section correspondante de la STD avant de coder
3. **Après chaque étape majeure**, vérifie que l'application compile/démarre correctement
4. **Écris les tests au fur et à mesure** — pas seulement en Phase 5. Chaque service et controller devrait avoir ses tests unitaires dès sa création
5. **Commite régulièrement** avec des messages clairs : `feat: add volume CRUD`, `fix: watcher reconnection logic`, etc.
6. Si tu as un doute sur un comportement, **la STD fait autorité**. Relis-la.

## Fichiers de référence

- `STD_Scanarr V1.1.md` — Spécification technique complète (source de vérité)
- Ce fichier (`CLAUDE.md`) — Instructions de travail et conventions
