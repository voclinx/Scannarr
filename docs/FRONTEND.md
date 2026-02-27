# Scanarr — Front-end Vue.js

> **Prérequis** : [API.md](API.md)
> **Version** : V1.2.1 (composants V1.5 dans QBIT_STATS_AND_SCORING.md)

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
      { path: 'settings', name: 'settings', component: SettingsView, meta: { minRole: 'ROLE_ADMIN' } },
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

export interface Volume {
  id: string;
  name: string;
  path: string;
  host_path: string;
  type: 'local' | 'network';
  status: 'active' | 'inactive' | 'error';
  total_space_bytes?: number;
  used_space_bytes?: number;
  last_scan_at?: string;
}

export interface MediaFile {
  id: string;
  volume_id: string;
  volume_name: string;
  file_path: string;
  file_name: string;
  file_size_bytes: number;
  hardlink_count: number;
  resolution?: string;
  codec?: string;
  quality?: string;
  is_linked_radarr: boolean;
  is_linked_media_player: boolean;
  detected_at: string;
}

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
  file_count: number;
  max_file_size_bytes: number;
  files_summary: MovieFileSummary[];
  is_monitored_radarr: boolean;
}

export interface MovieFileSummary {
  id: string;
  file_name: string;
  file_size_bytes: number;
  resolution: string;
  volume_name: string;
}

export interface MovieDetail extends Movie {
  files: MovieFileDetail[];
  radarr_instance?: { id: string; name: string };
  radarr_monitored: boolean;
}

export interface MovieFileDetail extends MediaFile {
  matched_by: 'radarr_api' | 'filename_parse' | 'manual';
  confidence: number;
}

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
  mapped_path?: string;
}

export interface MediaPlayerInstance {
  id: string;
  name: string;
  type: 'plex' | 'jellyfin';
  url: string;
  token: string;
  is_active: boolean;
}

export interface DashboardStats {
  total_movies: number;
  total_files: number;
  total_size_bytes: number;
  volumes: VolumeStats[];
  orphan_files_count: number;
  upcoming_deletions_count: number;
  recent_activity: ActivityLog[];
}

export interface VolumeStats {
  id: string;
  name: string;
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
// Structure du store
interface AuthState {
  user: User | null;
  accessToken: string | null;
  refreshToken: string | null;
  isAuthenticated: boolean;
}

// Actions
// login(email, password) → appel POST /auth/login, stocke tokens en localStorage
// logout() → supprime tokens, redirige vers /login
// refreshAccessToken() → appel POST /auth/refresh
// fetchMe() → appel GET /auth/me
// hasMinRole(role: UserRole) → boolean — vérifie la hiérarchie des rôles

// Hiérarchie des rôles (index = niveau de permission)
const ROLE_HIERARCHY = ['ROLE_GUEST', 'ROLE_USER', 'ROLE_ADVANCED_USER', 'ROLE_ADMIN'];
```

### 6.4 Composants clés — Comportement attendu

#### FileExplorerView.vue

- Sélecteur de volume en haut (dropdown avec les volumes actifs).
- Tableau PrimeVue DataTable avec les colonnes : Nom, Poids, Hardlinks, Radarr (badge vert/rouge), Lecteur (badge vert/rouge), Résolution, Actions.
- Barre de recherche en temps réel (debounce 300ms).
- Bouton "Supprimer" sur chaque ligne → ouvre `FileDeleteModal` avec les 2 options (physique seul / physique + Radarr).
- Bouton "Suppression globale" → ouvre modal avec avertissement recherche auto.

#### MoviesListView.vue

- Tableau PrimeVue DataTable avec les colonnes : Titre (+ année), Synopsis (tronqué), Nb fichiers, Poids max (avec tooltip), Actions (Voir / Supprimer).
- Barre de recherche + filtres (résolution, nombre de fichiers, monitored).
- Tri sur colonnes (titre, année, poids, nb fichiers).
- Clic sur une ligne → navigation vers MovieDetailView.

#### MovieDetailView.vue

- En-tête : affiche (poster TMDB à gauche), titre, année, genres, note, synopsis.
- Section "Fichiers liés" : tableau avec nom, volume, hardlinks, poids, résolution, actions.
- Bouton "Suppression globale" → ouvre `MovieGlobalDeleteModal` :
  - Liste des fichiers avec checkboxes (cochés par défaut).
  - Checkbox "Supprimer référence Radarr".
  - Checkbox "Supprimer référence lecteur multimédia".
  - Checkbox "Désactiver recherche automatique Radarr".
  - Bouton de confirmation rouge.

#### SettingsView.vue

- Navigation par onglets (Tabs PrimeVue) : Radarr, Lecteurs, Volumes, Torrent, Discord, TMDB.
- Chaque onglet = composant dédié (RadarrSettings, MediaPlayerSettings, VolumeSettings, TorrentSettings, DiscordSettings, TmdbSettings).
- RadarrSettings : liste des instances avec boutons Ajouter/Modifier/Supprimer/Tester.
- VolumeSettings : liste des volumes avec formulaire d'ajout (nom, chemin, type), bouton Scan.
- TorrentSettings : configuration qBittorrent (URL, username, password) avec bouton "Tester la connexion" (via `POST /api/v1/settings/test-qbittorrent`).
- DiscordSettings : URL du webhook + rappel en jours, avec bouton "Tester" (via `POST /api/v1/settings/test-discord`).
- TmdbSettings : clé API TMDB.
- Bouton "Tester la connexion" pour chaque service externe avec feedback visuel (spinner → succès vert / erreur rouge). Les tests passent par l'API backend, jamais directement depuis le frontend.

---

