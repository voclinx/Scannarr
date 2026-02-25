import { defineStore } from 'pinia'
import { ref } from 'vue'
import type {
  ScheduledDeletion,
  ScheduledDeletionDetail,
  CreateScheduledDeletionPayload,
  PaginationMeta,
} from '@/types'
import { useApi } from '@/composables/useApi'

export const useDeletionsStore = defineStore('deletions', () => {
  const api = useApi()

  const deletions = ref<ScheduledDeletion[]>([])
  const meta = ref<PaginationMeta>({ total: 0, page: 1, limit: 25, total_pages: 0 })
  const loading = ref(false)
  const currentDeletion = ref<ScheduledDeletionDetail | null>(null)
  const currentDeletionLoading = ref(false)

  async function fetchDeletions(params?: {
    page?: number
    limit?: number
    status?: string
  }): Promise<void> {
    loading.value = true
    try {
      const searchParams = new URLSearchParams()
      if (params?.page) searchParams.set('page', String(params.page))
      if (params?.limit) searchParams.set('limit', String(params.limit))
      if (params?.status) searchParams.set('status', params.status)

      const { data } = await api.get<{ data: ScheduledDeletion[]; meta: PaginationMeta }>(
        `/scheduled-deletions?${searchParams.toString()}`,
      )
      deletions.value = data.data
      meta.value = data.meta
    } finally {
      loading.value = false
    }
  }

  async function fetchDeletion(id: string): Promise<void> {
    currentDeletionLoading.value = true
    try {
      const { data } = await api.get<{ data: ScheduledDeletionDetail }>(
        `/scheduled-deletions/${id}`,
      )
      currentDeletion.value = data.data
    } finally {
      currentDeletionLoading.value = false
    }
  }

  async function createDeletion(
    payload: CreateScheduledDeletionPayload,
  ): Promise<ScheduledDeletion> {
    const { data } = await api.post<{ data: ScheduledDeletion }>('/scheduled-deletions', payload)
    await fetchDeletions()
    return data.data
  }

  async function cancelDeletion(id: string): Promise<void> {
    await api.delete(`/scheduled-deletions/${id}`)
    await fetchDeletions()
  }

  return {
    deletions,
    meta,
    loading,
    currentDeletion,
    currentDeletionLoading,
    fetchDeletions,
    fetchDeletion,
    createDeletion,
    cancelDeletion,
  }
})
