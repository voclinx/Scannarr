import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type { DeletionPreset } from '@/types'
import { useApi } from '@/composables/useApi'

export const usePresetsStore = defineStore('presets', () => {
  const api = useApi()
  const presets = ref<DeletionPreset[]>([])
  const loading = ref(false)

  const defaultPreset = computed(() => presets.value.find((p) => p.is_default) ?? presets.value[0] ?? null)

  async function fetchPresets(): Promise<void> {
    loading.value = true
    try {
      const { data } = await api.get<{ data: DeletionPreset[] }>('/deletion-presets')
      presets.value = data.data
    } finally {
      loading.value = false
    }
  }

  async function createPreset(payload: Omit<DeletionPreset, 'id' | 'is_system'>): Promise<DeletionPreset> {
    const { data } = await api.post<{ data: DeletionPreset }>('/deletion-presets', payload)
    await fetchPresets()
    return data.data
  }

  async function updatePreset(id: string, payload: Partial<DeletionPreset>): Promise<void> {
    await api.put(`/deletion-presets/${id}`, payload)
    await fetchPresets()
  }

  async function deletePreset(id: string): Promise<void> {
    await api.delete(`/deletion-presets/${id}`)
    presets.value = presets.value.filter((p) => p.id !== id)
  }

  return { presets, loading, defaultPreset, fetchPresets, createPreset, updatePreset, deletePreset }
})
