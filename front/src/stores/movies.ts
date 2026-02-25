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
}

export const useMoviesStore = defineStore('movies', () => {
  const api = useApi()
  const movies = ref<Movie[]>([])
  const meta = ref<PaginationMeta>({ total: 0, page: 1, limit: 25, total_pages: 0 })
  const loading = ref(false)
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

    try {
      const { data } = await api.get<{ data: Movie[]; meta: PaginationMeta }>(
        `/movies?${params.toString()}`,
      )
      movies.value = data.data
      meta.value = data.meta
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
    },
  ): Promise<{
    files_deleted: number
    radarr_dereferenced: boolean
    radarr_auto_search_disabled: boolean
    warning?: string
  }> {
    const { data } = await api.delete<{
      data: {
        message: string
        files_deleted: number
        radarr_dereferenced: boolean
        radarr_auto_search_disabled: boolean
        media_player_reference_kept: boolean
        warning?: string
      }
    }>(`/movies/${id}`, { data: options })

    return data.data
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

  function resetFilters(): void {
    filters.value = {
      page: 1,
      limit: 25,
      sort: 'title',
      order: 'ASC',
    }
    fetchMovies()
  }

  return {
    movies,
    meta,
    loading,
    currentMovie,
    currentMovieLoading,
    filters,
    fetchMovies,
    fetchMovie,
    deleteMovie,
    triggerSync,
    setPage,
    setSort,
    setSearch,
    resetFilters,
  }
})
