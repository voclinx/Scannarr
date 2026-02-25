import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type { MediaFile, PaginationMeta } from '@/types'
import { useApi } from '@/composables/useApi'

export interface FileFilters {
  volume_id?: string
  search?: string
  sort?: string
  order?: 'ASC' | 'DESC'
  is_linked_radarr?: boolean | null
  min_hardlinks?: number | null
  page?: number
  limit?: number
}

export const useFilesStore = defineStore('files', () => {
  const api = useApi()
  const files = ref<MediaFile[]>([])
  const meta = ref<PaginationMeta>({ total: 0, page: 1, limit: 25, total_pages: 0 })
  const loading = ref(false)
  const filters = ref<FileFilters>({
    page: 1,
    limit: 25,
    sort: 'detected_at',
    order: 'DESC',
  })

  const totalSize = computed(() => files.value.reduce((sum, f) => sum + f.file_size_bytes, 0))

  async function fetchFiles(overrideFilters?: Partial<FileFilters>): Promise<void> {
    loading.value = true

    if (overrideFilters) {
      filters.value = { ...filters.value, ...overrideFilters }
    }

    const params = new URLSearchParams()
    const f = filters.value

    if (f.volume_id) params.set('volume_id', f.volume_id)
    if (f.search) params.set('search', f.search)
    if (f.sort) params.set('sort', f.sort)
    if (f.order) params.set('order', f.order)
    if (f.is_linked_radarr !== null && f.is_linked_radarr !== undefined) {
      params.set('is_linked_radarr', String(f.is_linked_radarr))
    }
    if (f.min_hardlinks !== null && f.min_hardlinks !== undefined) {
      params.set('min_hardlinks', String(f.min_hardlinks))
    }
    if (f.page) params.set('page', String(f.page))
    if (f.limit) params.set('limit', String(f.limit))

    try {
      const { data } = await api.get<{ data: MediaFile[]; meta: PaginationMeta }>(
        `/files?${params.toString()}`,
      )
      files.value = data.data
      meta.value = data.meta
    } finally {
      loading.value = false
    }
  }

  async function deleteFile(
    id: string,
    options: { delete_physical?: boolean; delete_radarr_reference?: boolean } = {},
  ): Promise<{ physical_deleted: boolean; radarr_dereferenced: boolean }> {
    const { data } = await api.delete<{
      data: { message: string; physical_deleted: boolean; radarr_dereferenced: boolean }
    }>(`/files/${id}`, { data: options })

    // Remove from local list
    files.value = files.value.filter((f) => f.id !== id)
    if (meta.value.total > 0) {
      meta.value = { ...meta.value, total: meta.value.total - 1 }
    }

    return data.data
  }

  function setPage(page: number): void {
    filters.value.page = page
    fetchFiles()
  }

  function setSort(field: string, order: 'ASC' | 'DESC'): void {
    filters.value.sort = field
    filters.value.order = order
    filters.value.page = 1
    fetchFiles()
  }

  function setSearch(search: string): void {
    filters.value.search = search
    filters.value.page = 1
    fetchFiles()
  }

  function setVolumeFilter(volumeId: string | undefined): void {
    filters.value.volume_id = volumeId
    filters.value.page = 1
    fetchFiles()
  }

  function resetFilters(): void {
    filters.value = {
      page: 1,
      limit: 25,
      sort: 'detected_at',
      order: 'DESC',
    }
    fetchFiles()
  }

  return {
    files,
    meta,
    loading,
    filters,
    totalSize,
    fetchFiles,
    deleteFile,
    setPage,
    setSort,
    setSearch,
    setVolumeFilter,
    resetFilters,
  }
})
