import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { RadarrInstance, MediaPlayerInstance } from '@/types'
import { useApi } from '@/composables/useApi'

export const useSettingsStore = defineStore('settings', () => {
  const api = useApi()

  // Settings
  const settings = ref<Record<string, string | null>>({})
  const settingsLoading = ref(false)

  // Radarr instances
  const radarrInstances = ref<RadarrInstance[]>([])
  const radarrLoading = ref(false)

  // Media players
  const mediaPlayers = ref<MediaPlayerInstance[]>([])
  const mediaPlayersLoading = ref(false)

  // ---- Settings ----

  async function fetchSettings(): Promise<void> {
    settingsLoading.value = true
    try {
      const { data } = await api.get<{ data: Record<string, string | null> }>('/settings')
      settings.value = data.data
    } finally {
      settingsLoading.value = false
    }
  }

  async function updateSettings(updates: Record<string, string | null>): Promise<string[]> {
    const { data } = await api.put<{ data: { updated_keys: string[] } }>('/settings', updates)
    // Refresh after update
    await fetchSettings()
    return data.data.updated_keys
  }

  // ---- Radarr ----

  async function fetchRadarrInstances(): Promise<void> {
    radarrLoading.value = true
    try {
      const { data } = await api.get<{ data: RadarrInstance[] }>('/radarr-instances')
      radarrInstances.value = data.data
    } finally {
      radarrLoading.value = false
    }
  }

  async function createRadarrInstance(payload: {
    name: string
    url: string
    api_key: string
    is_active?: boolean
  }): Promise<RadarrInstance> {
    const { data } = await api.post<{ data: RadarrInstance }>('/radarr-instances', payload)
    await fetchRadarrInstances()
    return data.data
  }

  async function updateRadarrInstance(
    id: string,
    payload: Partial<{ name: string; url: string; api_key: string; is_active: boolean }>,
  ): Promise<RadarrInstance> {
    const { data } = await api.put<{ data: RadarrInstance }>(`/radarr-instances/${id}`, payload)
    await fetchRadarrInstances()
    return data.data
  }

  async function deleteRadarrInstance(id: string): Promise<void> {
    await api.delete(`/radarr-instances/${id}`)
    radarrInstances.value = radarrInstances.value.filter((i) => i.id !== id)
  }

  async function testRadarrConnection(
    id: string,
  ): Promise<{ success: boolean; version?: string; movies_count?: number; error?: string }> {
    try {
      const { data } = await api.post<{
        data: { success: boolean; version: string; movies_count: number }
      }>(`/radarr-instances/${id}/test`)
      return data.data
    } catch (err: unknown) {
      const error = err as { response?: { data?: { error?: { message?: string } } } }
      return {
        success: false,
        error: error.response?.data?.error?.message || 'Connection test failed',
      }
    }
  }

  async function fetchRadarrRootFolders(
    id: string,
  ): Promise<Array<{ id: number; path: string; freeSpace: number }>> {
    const { data } = await api.get<{
      data: Array<{ id: number; path: string; freeSpace: number }>
    }>(`/radarr-instances/${id}/root-folders`)
    return data.data
  }

  // ---- Media Players ----

  async function fetchMediaPlayers(): Promise<void> {
    mediaPlayersLoading.value = true
    try {
      const { data } = await api.get<{ data: MediaPlayerInstance[] }>('/media-players')
      mediaPlayers.value = data.data
    } finally {
      mediaPlayersLoading.value = false
    }
  }

  async function createMediaPlayer(payload: {
    name: string
    type: 'plex' | 'jellyfin'
    url: string
    token: string
    is_active?: boolean
  }): Promise<MediaPlayerInstance> {
    const { data } = await api.post<{ data: MediaPlayerInstance }>('/media-players', payload)
    await fetchMediaPlayers()
    return data.data
  }

  async function updateMediaPlayer(
    id: string,
    payload: Partial<{
      name: string
      type: 'plex' | 'jellyfin'
      url: string
      token: string
      is_active: boolean
    }>,
  ): Promise<MediaPlayerInstance> {
    const { data } = await api.put<{ data: MediaPlayerInstance }>(`/media-players/${id}`, payload)
    await fetchMediaPlayers()
    return data.data
  }

  async function deleteMediaPlayer(id: string): Promise<void> {
    await api.delete(`/media-players/${id}`)
    mediaPlayers.value = mediaPlayers.value.filter((p) => p.id !== id)
  }

  async function testMediaPlayerConnection(
    id: string,
  ): Promise<{ success: boolean; name?: string; version?: string; error?: string }> {
    try {
      const { data } = await api.post<{
        data: { success: boolean; name: string; version: string }
      }>(`/media-players/${id}/test`)
      return data.data
    } catch (err: unknown) {
      const error = err as { response?: { data?: { error?: { message?: string } } } }
      return {
        success: false,
        error: error.response?.data?.error?.message || 'Connection test failed',
      }
    }
  }

  return {
    // Settings
    settings,
    settingsLoading,
    fetchSettings,
    updateSettings,
    // Radarr
    radarrInstances,
    radarrLoading,
    fetchRadarrInstances,
    createRadarrInstance,
    updateRadarrInstance,
    deleteRadarrInstance,
    testRadarrConnection,
    fetchRadarrRootFolders,
    // Media Players
    mediaPlayers,
    mediaPlayersLoading,
    fetchMediaPlayers,
    createMediaPlayer,
    updateMediaPlayer,
    deleteMediaPlayer,
    testMediaPlayerConnection,
  }
})
