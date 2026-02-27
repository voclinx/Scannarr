import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useApi } from '@/composables/useApi'
import type { Watcher, WatcherConfig, WatcherLog } from '@/types'

export const useWatchersStore = defineStore('watchers', () => {
  const api = useApi()
  const watchers = ref<Watcher[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchWatchers() {
    loading.value = true
    error.value = null
    try {
      const { data } = await api.get('/watchers')
      watchers.value = data.data
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : 'Erreur lors du chargement des watchers'
    } finally {
      loading.value = false
    }
  }

  async function approveWatcher(id: string): Promise<Watcher> {
    const { data } = await api.post(`/watchers/${id}/approve`)
    const updated: Watcher = data.data
    const idx = watchers.value.findIndex((w) => w.id === id)
    if (idx !== -1) watchers.value[idx] = updated
    return updated
  }

  async function updateConfig(id: string, config: Partial<WatcherConfig>): Promise<Watcher> {
    const { data } = await api.put(`/watchers/${id}/config`, config)
    const updated: Watcher = data.data
    const idx = watchers.value.findIndex((w) => w.id === id)
    if (idx !== -1) watchers.value[idx] = updated
    return updated
  }

  async function updateName(id: string, name: string): Promise<Watcher> {
    const { data } = await api.put(`/watchers/${id}/name`, { name })
    const updated: Watcher = data.data
    const idx = watchers.value.findIndex((w) => w.id === id)
    if (idx !== -1) watchers.value[idx] = updated
    return updated
  }

  async function revokeWatcher(id: string): Promise<void> {
    const { data } = await api.post(`/watchers/${id}/revoke`)
    const updated: Watcher = data.data
    const idx = watchers.value.findIndex((w) => w.id === id)
    if (idx !== -1) watchers.value[idx] = updated
  }

  async function deleteWatcher(id: string): Promise<void> {
    await api.delete(`/watchers/${id}`)
    watchers.value = watchers.value.filter((w) => w.id !== id)
  }

  async function toggleDebug(id: string): Promise<Watcher> {
    const { data } = await api.post(`/watchers/${id}/debug`)
    const updated: Watcher = data.data
    const idx = watchers.value.findIndex((w) => w.id === id)
    if (idx !== -1) watchers.value[idx] = updated
    return updated
  }

  async function fetchLogs(
    id: string,
    params: { level?: string; limit?: number; offset?: number } = {},
  ): Promise<{ logs: WatcherLog[]; total: number }> {
    const { data } = await api.get(`/watchers/${id}/logs`, { params })
    return { logs: data.data, total: data.meta?.total ?? 0 }
  }

  return {
    watchers,
    loading,
    error,
    fetchWatchers,
    approveWatcher,
    updateConfig,
    updateName,
    revokeWatcher,
    deleteWatcher,
    toggleDebug,
    fetchLogs,
  }
})
