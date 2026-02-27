import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { SuggestionItem, PaginationMeta } from '@/types'
import { useApi } from '@/composables/useApi'

export interface SuggestionFilters {
  preset_id?: string
  seeding_status?: 'all' | 'orphans_only' | 'seeding_only'
  volume_id?: string
  sort?: string
  page?: number
  per_page?: number
}

export const useSuggestionsStore = defineStore('suggestions', () => {
  const api = useApi()
  const suggestions = ref<SuggestionItem[]>([])
  const meta = ref<PaginationMeta>({ total: 0, page: 1, limit: 50, total_pages: 0 })
  const loading = ref(false)
  const filters = ref<SuggestionFilters>({ seeding_status: 'all', sort: 'score_desc', page: 1, per_page: 50 })

  async function fetchSuggestions(overrides?: Partial<SuggestionFilters>): Promise<void> {
    if (overrides) filters.value = { ...filters.value, ...overrides }
    loading.value = true
    try {
      const params = new URLSearchParams()
      const f = filters.value
      if (f.preset_id) params.set('preset_id', f.preset_id)
      if (f.seeding_status) params.set('seeding_status', f.seeding_status)
      if (f.volume_id) params.set('volume_id', f.volume_id)
      if (f.sort) params.set('sort', f.sort)
      if (f.page) params.set('page', String(f.page))
      if (f.per_page) params.set('per_page', String(f.per_page))

      const { data } = await api.get<{ data: SuggestionItem[]; meta: PaginationMeta }>(
        `/suggestions?${params.toString()}`,
      )
      suggestions.value = data.data
      meta.value = data.meta
    } finally {
      loading.value = false
    }
  }

  async function batchDelete(
    items: Array<{ movie_id: string; file_ids: string[] }>,
    options: { delete_radarr_reference?: boolean; disable_radarr_auto_search?: boolean },
  ): Promise<void> {
    await api.post('/suggestions/batch-delete', { items, ...options })
  }

  async function batchSchedule(
    items: Array<{ movie_id: string; file_ids: string[] }>,
    scheduled_date: string,
    options: { delete_radarr_reference?: boolean; disable_radarr_auto_search?: boolean },
  ): Promise<void> {
    await api.post('/suggestions/batch-schedule', { items, scheduled_date, ...options })
  }

  return { suggestions, meta, loading, filters, fetchSuggestions, batchDelete, batchSchedule }
})
