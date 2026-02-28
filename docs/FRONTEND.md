# Scanarr — Front-end Vue.js

> **Prérequis** : [API.md](API.md)
> **Version** : V2.0

---

## 6. Front-end Vue.js

### 6.1 Routes

```typescript
const routes = [
  // Public
  { path: '/login', name: 'login', component: LoginView, meta: { guest: true } },
  { path: '/setup', name: 'setup', component: SetupWizardView, meta: { guest: true } },

  // Authenticated (AppLayout wrapper)
  {
    path: '/',
    component: AppLayout,
    meta: { requiresAuth: true },
    children: [
      { path: '', name: 'dashboard', component: DashboardView, meta: { minRole: 'ROLE_GUEST' } },
      { path: 'files', name: 'files', component: FileExplorerView, meta: { minRole: 'ROLE_GUEST' } },
      { path: 'movies', name: 'movies', component: MoviesListView, meta: { minRole: 'ROLE_GUEST' } },
      { path: 'movies/:id', name: 'movie-detail', component: MovieDetailView, meta: { minRole: 'ROLE_GUEST' } },
      { path: 'deletions', name: 'deletions', component: ScheduledDeletionsView, meta: { minRole: 'ROLE_USER' } },
      { path: 'suggestions', name: 'suggestions', component: SuggestionsView, meta: { minRole: 'ROLE_ADVANCED_USER' } },
      { path: 'settings', name: 'settings', component: SettingsView, meta: { minRole: 'ROLE_ADMIN' } },
      { path: 'settings/presets', name: 'presets', component: PresetsSettingsView, meta: { minRole: 'ROLE_ADVANCED_USER' } },
      { path: 'settings/trackers', name: 'trackers', component: TrackerRulesSettingsView, meta: { minRole: 'ROLE_ADMIN' } },
      { path: 'users', name: 'users', component: UsersManagementView, meta: { minRole: 'ROLE_ADMIN' } },
    ]
  }
];
```

### 6.2 Types TypeScript

```typescript
// types/index.ts

export type UserRole = 'ROLE_ADMIN' | 'ROLE_ADVANCED_USER' | 'ROLE_USER' | 'ROLE_GUEST';

export interface User {
  id: string;
  email: string;
  username: string;
  role: UserRole;
  is_active: boolean;
  created_at: string;
  last_login_at?: string;
}

// === Watchers & Volumes (V2.0) ===

export interface Watcher {
  id: string;
  name: string;
  hostname?: string;
  status: 'connected' | 'disconnected' | 'error';
  scan_extensions: string[];
  disable_deletion: boolean;
  volumes: WatcherVolume[];
  last_seen_at?: string;
}

export interface WatcherVolume {
  id: string;
  watcher_id: string;
  name: string;
  path: string;
  status: 'active' | 'inactive' | 'error';
  total_space_bytes?: number;
  used_space_bytes?: number;
  last_scan_at?: string;
}

// === Media Files (V2.0 — inode-based) ===

export interface MediaFile {
  id: string;
  inode: number;
  device_id: number;
  movie_id?: string;
  file_size_bytes: number;
  hardlink_count: number;
  resolution?: string;
  codec?: string;
  quality?: string;
  is_protected: boolean;
  is_linked_radarr: boolean;
  is_linked_media_player: boolean;
  file_paths: FilePath[];
  created_at: string;
}

export interface FilePath {
  id: string;
  volume_id: string;
  volume_name: string;
  relative_path: string;
  filename: string;
  discovered_at: string;
}

// === Movies ===

export interface Movie {
  id: string;
  tmdb_id?: number;
  title: string;
  original_title?: string;
  year?: number;
  synopsis?: string;
  poster_url?: string;
  backdrop_url?: string;
  genres?: string;
  rating?: number;
  runtime_minutes?: number;
  is_protected: boolean;
  file_count: number;
  paths_count: number;
  total_size_bytes: number;
  files_summary: MovieFileSummary[];
  is_monitored_radarr: boolean;
  // qBit stats (enrichis)
  best_ratio?: number;
  worst_ratio?: number;
  seeding_status?: 'seeding' | 'orphan' | 'mixed';
  cross_seed_count?: number;
  ratio_trend?: 'rising' | 'stable' | 'falling';
}

export interface MovieFileSummary {
  media_file_id: string;
  filename: string;
  file_size_bytes: number;
  resolution: string;
  paths_count: number;
}

export interface MovieDetail extends Movie {
  files: MovieFileDetail[];
  radarr_instance?: { id: string; name: string };
  radarr_monitored: boolean;
}

export interface MovieFileDetail extends MediaFile {
  matched_by: 'radarr_api' | 'filename_parse' | 'manual' | 'suffix_match';
  confidence: number;
}

// === Scheduled Deletions ===

export interface ScheduledDeletion {
  id: string;
  scheduled_date: string;
  execution_time: string;
  status: 'pending' | 'reminder_sent' | 'executing' | 'waiting_watcher' | 'completed' | 'failed' | 'cancelled';
  delete_physical_files: boolean;
  delete_radarr_reference: boolean;
  delete_media_player_reference: boolean;
  disable_radarr_auto_search: boolean;
  reminder_days_before: number;
  items_count: number;
  total_files_count: number;
  created_by: string;
  created_at: string;
}

// === External Services ===

export interface RadarrInstance {
  id: string;
  name: string;
  url: string;
  api_key: string;
  is_active: boolean;
  root_folders?: RadarrRootFolder[];
  last_sync_at?: string;
}

export interface RadarrRootFolder {
  id: number;
  path: string;
  // Note V2.0 : plus de mapped_path
}

export interface MediaPlayerInstance {
  id: string;
  name: string;
  type: 'plex' | 'jellyfin';
  url: string;
  token: string;
  is_active: boolean;
}

// === Dashboard ===

export interface DashboardStats {
  total_movies: number;
  total_files: number;
  total_paths: number;
  total_size_bytes: number;
  watchers: WatcherStatus[];
  orphan_files_count: number;
  upcoming_deletions_count: number;
  recent_activity: ActivityLog[];
}

export interface WatcherStatus {
  id: string;
  name: string;
  status: 'connected' | 'disconnected' | 'error';
  volumes_count: number;
  total_space_bytes: number;
  used_space_bytes: number;
  file_count: number;
}

export interface ActivityLog {
  action: string;
  entity_type: string;
  details: Record<string, unknown>;
  user: string;
  created_at: string;
}
```

### 6.3 Stores Pinia

#### Auth Store (`stores/auth.ts`)

```typescript
interface AuthState {
  user: User | null;
  accessToken: string | null;
  refreshToken: string | null;
  isAuthenticated: boolean;
}

// Actions
// login(email, password) → POST /auth/login
// logout() → supprime tokens
// refreshAccessToken() → POST /auth/refresh
// fetchMe() → GET /auth/me
// hasMinRole(role: UserRole) → boolean

const ROLE_HIERARCHY = ['ROLE_GUEST', 'ROLE_USER', 'ROLE_ADVANCED_USER', 'ROLE_ADMIN'];
```

### 6.4 Composants clés — Comportement attendu

#### FileExplorerView.vue

- Sélecteur de watcher + volume en haut (dropdowns).
- Tableau PrimeVue DataTable : Nom, Poids, Chemins (nombre de file_paths), Radarr (badge), Lecteur (badge), Résolution, Actions.
- Le nombre de chemins remplace l'ancien "Hardlinks" qui était le nlink brut.
- Bouton "Supprimer" → ouvre `FileDeleteModal` avec la liste de tous les chemins qui seront supprimés.
- Bouton "Suppression globale" → supprime tous les file_paths du media_file.

#### MoviesListView.vue

- Tableau enrichi avec colonnes qBit (ratio, seed time, seeding status, cross-seed).
- Badge 📂 pour les films multi-paths.
- Badge 🛡 pour les films protégés.
- Tri et filtre sur toutes les colonnes.

#### MovieDetailView.vue

- Affichage des fichiers avec **tous les chemins connus** (file_paths) groupés par media_file.
- Section détaillée par fichier avec les stats torrent, les trackers, et les file_paths.

#### SettingsView.vue — Onglet Watchers (V2.0)

Remplace l'onglet "Volumes". Gestion centralisée des watchers et de leurs volumes.

```
┌─────────────────────────────────────────────────────────────────────┐
│  Watchers                                            [+ Ajouter]   │
│─────────────────────────────────────────────────────────────────────│
│                                                                     │
│  🟢 Watcher NAS Principal (nas-principal)            [Configurer]   │
│     Connecté depuis 2h │ 3 volumes │ 2400 fichiers                 │
│     ├── Films HD          /volume1/filmarr/media/movies    🟢      │
│     ├── Torrents HD       /volume1/filmarr/torrents/movies 🟢      │
│     └── Cross-seed        /volume1/filmarr/links           🟢      │
│                                                                     │
│  🔴 Watcher Backup (nas-backup)                      [Configurer]   │
│     Déconnecté depuis 3j │ 1 volume │ 800 fichiers                 │
│     └── Backup Films      /volume2/backup/movies           🔴      │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

#### WatcherConfigDialog.vue (V2.0)

Dialogue de configuration d'un watcher :

```
┌─────────────────────────────────────────────────────────────────────┐
│  Configuration — Watcher NAS Principal                              │
│─────────────────────────────────────────────────────────────────────│
│                                                                     │
│  Nom :        [Watcher NAS Principal          ]                     │
│  Token :      [watcher-unique-token-abc123    ] [🔄 Régénérer]     │
│                                                                     │
│  Extensions : [mkv] [mp4] [avi] [m4v] [ts] [wmv] [+ Ajouter]      │
│                                                                     │
│  ☐ Désactiver la suppression (mode lecture seule)                   │
│                                                                     │
│  ─── Volumes surveillés ──────────────────────────── [+ Ajouter]── │
│                                                                     │
│  Nom              Chemin                                 Actions    │
│  Films HD         /volume1/filmarr/media/movies         [✏️] [🗑️]  │
│  Torrents HD      /volume1/filmarr/torrents/movies      [✏️] [🗑️]  │
│  Cross-seed       /volume1/filmarr/links                [✏️] [🗑️]  │
│                                                                     │
│                               [Annuler]  [Sauvegarder]             │
└─────────────────────────────────────────────────────────────────────┘
```

#### SettingsView.vue — Onglet qBittorrent (V2.0)

L'onglet est simplifié : plus de section "Path Mappings" (éliminé). Il reste :

- Configuration connexion (URL, username, password)
- Bouton "Tester la connexion"
- Intervalle de sync (minutes)
- Bouton "Sync maintenant" avec barre de progression
- Dernier rapport de sync (lien vers le rapport détaillé)

---
