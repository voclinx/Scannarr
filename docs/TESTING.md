# Scanarr — Plan de Tests

> **Prérequis** : Tous les fichiers de spec
> **Version** : V1.2.1

---

## 13. Cas de test

### 13.1 Tests Back-end (PHPUnit)

#### AuthController

```
TEST-AUTH-001: Setup initial — créer le premier admin quand setup_completed=false
  → POST /api/v1/auth/setup avec email, username, password valides
  → Attendu : 201, user créé avec ROLE_ADMIN, setting setup_completed=true
  → Vérifier : password hashé en BDD (pas en clair)

TEST-AUTH-002: Setup initial — refuser si déjà complété
  → POST /api/v1/auth/setup quand setup_completed=true
  → Attendu : 403, message "Setup already completed"

TEST-AUTH-003: Login — credentials valides
  → POST /api/v1/auth/login avec email + password corrects
  → Attendu : 200, access_token + refresh_token retournés

TEST-AUTH-004: Login — credentials invalides
  → POST /api/v1/auth/login avec mauvais password
  → Attendu : 401, message "Invalid credentials"

TEST-AUTH-005: Login — compte désactivé
  → POST /api/v1/auth/login avec un compte is_active=false
  → Attendu : 401, message "Account is disabled"

TEST-AUTH-006: Refresh token — token valide
  → POST /api/v1/auth/refresh avec un refresh_token valide
  → Attendu : 200, nouveau access_token

TEST-AUTH-007: Refresh token — token expiré
  → POST /api/v1/auth/refresh avec un refresh_token expiré
  → Attendu : 401

TEST-AUTH-008: Me — utilisateur connecté
  → GET /api/v1/auth/me avec Bearer token valide
  → Attendu : 200, infos utilisateur
```

#### Permissions / Rôles

```
TEST-ROLE-001: Admin accède aux paramètres
  → GET /api/v1/settings avec token ROLE_ADMIN
  → Attendu : 200

TEST-ROLE-002: Utilisateur standard ne peut pas accéder aux paramètres
  → GET /api/v1/settings avec token ROLE_USER
  → Attendu : 403

TEST-ROLE-003: Invité ne peut pas supprimer un fichier
  → DELETE /api/v1/files/{id} avec token ROLE_GUEST
  → Attendu : 403

TEST-ROLE-004: Utilisateur avancé peut supprimer un fichier
  → DELETE /api/v1/files/{id} avec token ROLE_ADVANCED_USER
  → Attendu : 200

TEST-ROLE-005: Utilisateur avancé ne peut annuler que ses propres suppressions planifiées
  → DELETE /api/v1/scheduled-deletions/{id} avec token ROLE_ADVANCED_USER sur une deletion créée par un autre
  → Attendu : 403

TEST-ROLE-006: Admin peut annuler n'importe quelle suppression planifiée
  → DELETE /api/v1/scheduled-deletions/{id} avec token ROLE_ADMIN sur une deletion de quelqu'un d'autre
  → Attendu : 200
```

#### Volumes

```
TEST-VOL-001: Créer un volume — succès
  → POST /api/v1/volumes avec name, path, type valides
  → Attendu : 201, volume créé

TEST-VOL-002: Créer un volume — chemin dupliqué
  → POST /api/v1/volumes avec un path déjà existant
  → Attendu : 422, "Path already exists"

TEST-VOL-003: Créer un volume — chemin inexistant sur le filesystem
  → POST /api/v1/volumes avec path=/nonexistent/path
  → Attendu : 422, "Path does not exist or is not accessible"

TEST-VOL-004: Déclencher un scan
  → POST /api/v1/volumes/{id}/scan
  → Attendu : 202, message "Scan initiated"
  → Vérifier : message envoyé au watcher via WebSocket

TEST-VOL-005: Supprimer un volume
  → DELETE /api/v1/volumes/{id}
  → Attendu : 204, volume supprimé + media_files associés supprimés (CASCADE)
```

#### Films

```
TEST-MOVIE-001: Lister les films — pagination
  → GET /api/v1/movies?page=1&limit=10
  → Attendu : 200, max 10 films, meta.total correct

TEST-MOVIE-002: Lister les films — recherche par titre
  → GET /api/v1/movies?search=inception
  → Attendu : 200, résultats contenant "inception" dans le titre

TEST-MOVIE-003: Lister les films — tri par année descendant
  → GET /api/v1/movies?sort=year&order=desc
  → Attendu : 200, films triés du plus récent au plus ancien

TEST-MOVIE-004: Détail d'un film — inclut les fichiers liés
  → GET /api/v1/movies/{id}
  → Attendu : 200, objet movie avec tableau files[] rempli

TEST-MOVIE-005: Suppression globale — à la carte
  → DELETE /api/v1/movies/{id} avec file_ids, delete_radarr_reference=true
  → Attendu : 200, fichiers physiques supprimés, Radarr déréférencé
  → Vérifier : media_files supprimés en BDD, log d'activité créé

TEST-MOVIE-006: Suppression globale — film inexistant
  → DELETE /api/v1/movies/{uuid-inexistant}
  → Attendu : 404

TEST-MOVIE-007: Sync Radarr — récupération des films
  → POST /api/v1/movies/sync
  → Attendu : 202
  → Vérifier : films Radarr importés en BDD, enrichis via TMDB
```

#### Suppression planifiée

```
TEST-SCHED-001: Créer une suppression planifiée — succès
  → POST /api/v1/scheduled-deletions avec date future, items valides
  → Attendu : 201, status="pending"

TEST-SCHED-002: Créer une suppression planifiée — date passée
  → POST /api/v1/scheduled-deletions avec date dans le passé
  → Attendu : 422, "Scheduled date must be in the future"

TEST-SCHED-003: Créer une suppression planifiée — movie_id inexistant
  → POST /api/v1/scheduled-deletions avec un movie_id invalide dans items
  → Attendu : 422, "Movie not found: {id}"

TEST-SCHED-004: Annuler une suppression planifiée
  → DELETE /api/v1/scheduled-deletions/{id} (status=pending)
  → Attendu : 200, status="cancelled"

TEST-SCHED-005: Annuler une suppression déjà exécutée
  → DELETE /api/v1/scheduled-deletions/{id} (status=completed)
  → Attendu : 422, "Cannot cancel a completed deletion"

TEST-SCHED-006: Exécution automatique — vérifier que les fichiers sont supprimés à la date prévue
  → Créer une deletion pour aujourd'hui
  → Exécuter ProcessScheduledDeletionsCommand
  → Attendu : fichiers physiques supprimés, status="completed", notification Discord envoyée

TEST-SCHED-007: Exécution — fichier introuvable (déjà supprimé manuellement)
  → Créer une deletion pour un fichier qui n'existe plus sur le filesystem
  → Exécuter ProcessScheduledDeletionsCommand
  → Attendu : item status="failed", error_message="File not found", notification Discord d'erreur

TEST-SCHED-008: Rappel Discord — envoyé X jours avant
  → Créer une deletion pour dans 3 jours avec reminder_days_before=3
  → Exécuter SendDeletionRemindersCommand
  → Attendu : webhook Discord appelé, status="reminder_sent"
```

#### Watcher Message Handler

```
TEST-WH-001: Réception d'un événement file.created
  → Simuler un message WebSocket file.created
  → Attendu : media_file créé en BDD avec les bonnes infos

TEST-WH-002: Réception d'un événement file.deleted
  → Simuler un message WebSocket file.deleted pour un fichier en BDD
  → Attendu : media_file supprimé de la BDD

TEST-WH-003: Réception d'un événement file.renamed
  → Simuler un message WebSocket file.renamed
  → Attendu : media_file mis à jour (path, name)

TEST-WH-004: Réception scan.file — création de fichier
  → Simuler un message scan.file pour un fichier non existant en BDD
  → Attendu : media_file créé

TEST-WH-005: Réception scan.file — fichier déjà existant (mise à jour)
  → Simuler un message scan.file pour un fichier existant avec taille différente
  → Attendu : media_file mis à jour (size, hardlink_count)

TEST-WH-006: Réception scan.completed — mise à jour du volume
  → Simuler un message scan.completed
  → Attendu : volume.last_scan_at mis à jour, total_space et used_space mis à jour
```

### 13.2 Tests Front-end (Vitest)

```
TEST-FRONT-001: LoginView — connexion réussie redirige vers dashboard
TEST-FRONT-002: LoginView — erreur affichée si credentials invalides
TEST-FRONT-003: SetupWizard — formulaire de création admin affiché si non configuré
TEST-FRONT-004: AppLayout — sidebar masque les liens selon le rôle
TEST-FRONT-005: MoviesListView — recherche filtre le tableau
TEST-FRONT-006: MoviesListView — clic sur ligne navigue vers détail
TEST-FRONT-007: MovieDetailView — affiche les infos du film + fichiers
TEST-FRONT-008: MovieGlobalDeleteModal — checkboxes fonctionnent et envoient la bonne requête
TEST-FRONT-009: FileExplorerView — changement de volume recharge les fichiers
TEST-FRONT-010: ScheduledDeletionForm — validation de date future
TEST-FRONT-011: SettingsView — onglets navigation entre les composants
TEST-FRONT-012: RadarrSettings — test connexion affiche succès/erreur
TEST-FRONT-013: Auth store — refresh token automatique sur 401
TEST-FRONT-014: Auth guard — redirige vers login si non authentifié
TEST-FRONT-015: Auth guard — redirige vers dashboard si rôle insuffisant
```

### 13.3 Tests Watcher Go

```
TEST-GO-001: Config — charge correctement les variables d'environnement
TEST-GO-002: Config — valeurs par défaut si env vars manquantes
TEST-GO-003: Watcher — détecte la création d'un fichier .mkv
TEST-GO-004: Watcher — détecte la suppression d'un fichier
TEST-GO-005: Watcher — détecte le renommage d'un fichier
TEST-GO-006: Watcher — ignore les fichiers temporaires (.part, .tmp)
TEST-GO-007: Watcher — ignore les fichiers cachés (.*) 
TEST-GO-008: Watcher — ignore les dossiers système (@eaDir, .Trash)
TEST-GO-009: Scanner — scan récursif retourne tous les fichiers médias
TEST-GO-010: Scanner — calcule correctement le nombre de hardlinks
TEST-GO-011: Scanner — ignore les fichiers non-médias
TEST-GO-012: Scanner — envoie scan.progress toutes les 100 fichiers
TEST-GO-013: Scanner — envoie scan.completed avec les bonnes stats
TEST-GO-014: WebSocket client — connexion réussie avec token valide
TEST-GO-015: WebSocket client — reconnexion automatique après déconnexion
TEST-GO-016: WebSocket client — reconnexion avec backoff exponentiel
TEST-GO-017: WebSocket client — envoie un ping périodique
TEST-GO-018: WebSocket client — réception et exécution de command.scan
TEST-GO-019: WebSocket client — réception et exécution de command.watch.add
TEST-GO-020: WebSocket client — réception et exécution de command.watch.remove
```

### 13.4 Tests d'intégration

```
TEST-INT-001: Flux complet — ajout d'un volume → scan → fichiers visibles dans l'explorateur
  1. POST /api/v1/volumes (créer un volume)
  2. POST /api/v1/volumes/{id}/scan (déclencher scan)
  3. Le watcher scanne et envoie les fichiers via WebSocket
  4. GET /api/v1/files?volume_id={id} → fichiers présents

TEST-INT-002: Flux complet — sync Radarr → films liés aux fichiers
  1. Configurer une instance Radarr (POST /api/v1/radarr-instances)
  2. Déclencher sync (POST /api/v1/movies/sync)
  3. GET /api/v1/movies → films présents avec file_count > 0

TEST-INT-003: Flux complet — suppression planifiée de bout en bout
  1. Créer une deletion planifiée pour aujourd'hui
  2. Vérifier que le fichier existe physiquement
  3. Exécuter ProcessScheduledDeletionsCommand
  4. Vérifier que le fichier n'existe plus physiquement
  5. Vérifier que media_file est supprimé en BDD
  6. Vérifier que la notification Discord a été envoyée (mock)

TEST-INT-004: Flux complet — watcher détecte un nouveau fichier → matching avec film
  1. Volume configuré + films synchro depuis Radarr
  2. Simuler création d'un fichier dans le volume
  3. Watcher envoie file.created
  4. Back-end crée le media_file + tente le matching
  5. GET /api/v1/movies/{id} → fichier présent dans la liste

TEST-INT-005: Flux complet — suppression globale d'un film
  1. Film avec 3 fichiers sur 2 volumes
  2. DELETE /api/v1/movies/{id} avec tous les file_ids + delete_radarr=true
  3. Vérifier : 3 fichiers supprimés physiquement
  4. Vérifier : référence Radarr supprimée (mock API)
  5. Vérifier : activity_log créé
```

---

