import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { Movie, MovieDetail, PaginationMeta } from '@/types'
import { useApi } from '@/composables/useApi'

export interface MovieFilters {
  search?: string
  sort?: string
  order?: 'ASC' | 'DESC'
  radarr_instance_id?: string
  page?: number
  limit?: number
  // Advanced filters
  seeding_status?: string // comma-separated: orphan,seeding,inactive
  in_qbit?: string // 'true' | 'false'
  in_media_player?: string // 'true' | 'false'
  radarr_monitored?: string // 'true' | 'false'
  is_protected?: string // 'true' | 'false'
  has_files?: string // 'true' | 'false'
  file_count_min?: string
  file_count_max?: string
}

export const useMoviesStore = defineStore('movies', () => {
  const api = useApi()
  const movies = ref<Movie[]>([])
  const meta = ref<PaginationMeta>({ total: 0, page: 1, limit: 25, total_pages: 0 })
  const loading = ref(false)
  const error = ref<string | null>(null)
  const currentMovie = ref<MovieDetail | null>(null)
  const currentMovieLoading = ref(false)
  const filters = ref<MovieFilters>({
    page: 1,
    limit: 25,
    sort: 'title',
    order: 'ASC',
  })

  async function fetchMovies(overrideFilters?: Partial<MovieFilters>): Promise<void> {
    loading.value = true

    if (overrideFilters) {
      filters.value = { ...filters.value, ...overrideFilters }
    }

    const params = new URLSearchParams()
    const f = filters.value

    if (f.search) params.set('search', f.search)
    if (f.sort) params.set('sort', f.sort)
    if (f.order) params.set('order', f.order)
    if (f.radarr_instance_id) params.set('radarr_instance_id', f.radarr_instance_id)
    if (f.page) params.set('page', String(f.page))
    if (f.limit) params.set('limit', String(f.limit))
    // Advanced filters
    if (f.seeding_status) params.set('seeding_status', f.seeding_status)
    if (f.in_qbit) params.set('in_qbit', f.in_qbit)
    if (f.in_media_player) params.set('in_media_player', f.in_media_player)
    if (f.radarr_monitored) params.set('radarr_monitored', f.radarr_monitored)
    if (f.is_protected) params.set('is_protected', f.is_protected)
    if (f.has_files) params.set('has_files', f.has_files)
    if (f.file_count_min) params.set('file_count_min', f.file_count_min)
    if (f.file_count_max) params.set('file_count_max', f.file_count_max)

    error.value = null
    try {
      const { data } = await api.get<{ data: Movie[]; meta: PaginationMeta }>(
        `/movies?${params.toString()}`,
      )
      movies.value = data.data
      meta.value = data.meta
    } catch (err: unknown) {
      const e = err as { response?: { status?: number; data?: { error?: { message?: string } } }; message?: string }
      const status = e.response?.status
      const msg = e.response?.data?.error?.message
      if (status === 401) {
        // Auth interceptor will handle this
      } else if (msg) {
        error.value = `Erreur ${status ?? ''} : ${msg}`
      } else {
        error.value = e.message || 'Erreur lors du chargement des films'
      }
    } finally {
      loading.value = false
    }
  }

  async function fetchMovie(id: string): Promise<void> {
    currentMovieLoading.value = true
    try {
      const { data } = await api.get<{ data: MovieDetail }>(`/movies/${id}`)
      currentMovie.value = data.data
    } finally {
      currentMovieLoading.value = false
    }
  }

  async function deleteMovie(
    id: string,
    options: {
      file_ids: string[]
      delete_radarr_reference: boolean
      delete_media_player_reference: boolean
      disable_radarr_auto_search: boolean
      replacement_map?: Record<string, string>
    },
  ): Promise<{
    message: string
    deletion_id: string
    status: string
    files_count: number
    radarr_dereferenced: boolean
    warning?: string
  }> {
    const { data } = await api.delete<{
      data: {
        message: string
        deletion_id: string
        status: string
        files_count: number
        radarr_dereferenced: boolean
        warning?: string
      }
    }>(`/movies/${id}`, { data: options })

    return data.data
  }

  async function protectMovie(id: string, isProtected: boolean): Promise<void> {
    await api.patch(`/movies/${id}/protect`, { is_protected: isProtected })
    if (currentMovie.value?.id === id) {
      currentMovie.value = { ...currentMovie.value, is_protected: isProtected }
    }
    const movie = movies.value.find((m) => m.id === id)
    if (movie) movie.is_protected = isProtected
  }

  async function triggerSync(): Promise<void> {
    await api.post('/movies/sync')
  }

  function setPage(page: number): void {
    filters.value.page = page
    fetchMovies()
  }

  function setSort(field: string, order: 'ASC' | 'DESC'): void {
    filters.value.sort = field
    filters.value.order = order
    filters.value.page = 1
    fetchMovies()
  }

  function setSearch(search: string): void {
    filters.value.search = search
    filters.value.page = 1
    fetchMovies()
  }

  function setFilter<K extends keyof MovieFilters>(key: K, value: MovieFilters[K]): void {
    filters.value[key] = value
    filters.value.page = 1
    fetchMovies()
  }

  function setFilters(newFilters: Partial<MovieFilters>): void {
    filters.value = { ...filters.value, ...newFilters, page: 1 }
    fetchMovies()
  }

  function resetFilters(): void {
    filters.value = {
      page: 1,
      limit: filters.value.limit ?? 25,
      sort: 'title',
      order: 'ASC',
    }
    fetchMovies()
  }

  return {
    movies,
    meta,
    loading,
    error,
    currentMovie,
    currentMovieLoading,
    filters,
    fetchMovies,
    fetchMovie,
    deleteMovie,
    protectMovie,
    triggerSync,
    setPage,
    setSort,
    setSearch,
    setFilter,
    setFilters,
    resetFilters,
  }
})
