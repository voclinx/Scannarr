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
  partial_hash?: string;
  is_protected: boolean;
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
  is_protected: boolean;
  best_ratio?: number;
  worst_ratio?: number;
  total_seed_time_max_seconds?: number;
  seeding_status?: 'seeding' | 'orphan' | 'mixed';
  cross_seed_count?: number;
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
  cross_seed_count: number;
  torrents: TorrentStat[];
}

export interface ScheduledDeletion {
  id: string;
  scheduled_date: string;
  execution_time: string;
  status: 'pending' | 'reminder_sent' | 'executing' | 'completed' | 'failed' | 'cancelled';
  delete_physical_files: boolean;
  delete_radarr_reference: boolean;
  delete_media_player_reference: boolean;
  reminder_days_before: number;
  items_count: number;
  total_files_count: number;
  created_by: string;
  created_at: string;
  executed_at?: string;
}

export interface ScheduledDeletionDetail extends ScheduledDeletion {
  reminder_sent_at?: string;
  execution_report?: Record<string, unknown>;
  items: ScheduledDeletionItemDetail[];
  updated_at: string;
}

export interface ScheduledDeletionItemDetail {
  id: string;
  movie: {
    id: string;
    title: string;
    year?: number;
    poster_url?: string;
  } | null;
  media_file_ids: string[];
  status: 'pending' | 'deleted' | 'failed' | 'skipped';
  error_message?: string;
}

export interface CreateScheduledDeletionPayload {
  scheduled_date: string;
  delete_physical_files: boolean;
  delete_radarr_reference: boolean;
  delete_media_player_reference: boolean;
  reminder_days_before: number;
  items: {
    movie_id: string;
    media_file_ids: string[];
  }[];
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

export interface ApiResponse<T> {
  data: T;
  meta?: PaginationMeta;
}

export interface ApiErrorResponse {
  error: {
    code: number;
    message: string;
    details?: Record<string, string>;
  };
}

export interface PaginationMeta {
  total: number;
  page: number;
  limit: number;
  total_pages: number;
}

export interface LoginResponse {
  access_token: string;
  refresh_token: string;
  expires_in: number;
  user: {
    id: string;
    username: string;
    role: UserRole;
  };
}

// V1.5 — qBit stats
export interface TorrentStat {
  id: string;
  torrent_hash: string;
  torrent_name: string;
  tracker_domain: string;
  ratio: number;
  seed_time_seconds: number;
  uploaded_bytes: number;
  status: 'seeding' | 'paused' | 'stalled' | 'error' | 'completed' | 'removed';
  added_at: string;
  tracker_rule_satisfied: boolean;
}

// V1.5 — Presets
export interface PresetCriteria {
  ratio: { enabled: boolean; threshold: number; weight: number; operator: string };
  seed_time: { enabled: boolean; threshold_days: number; weight: number; operator: string };
  file_size: { enabled: boolean; threshold_gb: number; weight: number; operator: string };
  orphan_qbit: { enabled: boolean; weight: number };
  cross_seed: { enabled: boolean; weight: number; per_tracker: boolean };
}

export interface PresetFilters {
  seeding_status: 'all' | 'orphans_only' | 'seeding_only';
  exclude_protected: boolean;
  min_score: number;
  max_results: number | null;
}

export interface DeletionPreset {
  id: string;
  name: string;
  is_system: boolean;
  is_default: boolean;
  criteria: PresetCriteria;
  filters: PresetFilters;
}

// V1.5 — Tracker rules
export interface TrackerRule {
  id: string;
  tracker_domain: string;
  min_seed_time_hours: number;
  min_ratio: number;
  is_auto_detected: boolean;
}

// Phase 5 — Watchers
export type WatcherStatus = 'pending' | 'approved' | 'connected' | 'disconnected' | 'revoked';

export interface WatcherConfig {
  watch_paths: string[];
  scan_on_start: boolean;
  log_level: string;
  reconnect_delay: string;
  ping_interval: string;
  log_retention_days: number;
  debug_log_retention_hours: number;
}

export interface Watcher {
  id: string;
  watcher_id: string;
  name: string;
  status: WatcherStatus;
  hostname: string;
  version: string;
  config: WatcherConfig;
  config_hash: string;
  last_seen_at?: string;
  created_at: string;
  updated_at: string;
}

export interface WatcherLog {
  id: string;
  level: 'debug' | 'info' | 'warn' | 'error';
  message: string;
  context?: Record<string, unknown>;
  created_at: string;
}

export interface WatcherLogsResponse {
  data: WatcherLog[];
  meta: {
    total: number;
    limit: number;
    offset: number;
  };
}

// V1.5 — Suggestions
export interface MovieSummary {
  id: string;
  title: string;
  year?: number;
  poster_url?: string;
}

export interface SuggestionFile {
  media_file_id: string;
  file_name: string;
  file_size_bytes: number;
  resolution?: string;
  torrents: TorrentStat[];
  cross_seed_count: number;
  is_protected: boolean;
  tracker_rule_satisfied: boolean;
}

export interface SuggestionItem {
  movie: MovieSummary;
  files: SuggestionFile[];
  score: number;
  score_breakdown: Record<string, number>;
  total_size_bytes: number;
  total_freed_bytes: number;
  files_count: number;
  multi_file: boolean;
  blocked_by_tracker_rules: boolean;
  blocked_reason: string | null;
}
