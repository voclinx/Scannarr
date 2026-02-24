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
  status: 'pending' | 'reminder_sent' | 'executing' | 'completed' | 'failed' | 'cancelled';
  delete_physical_files: boolean;
  delete_radarr_reference: boolean;
  delete_media_player_reference: boolean;
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
