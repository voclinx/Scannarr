import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { Volume } from '@/types'
import { useApi } from '@/composables/useApi'

export const useVolumesStore = defineStore('volumes', () => {
  const api = useApi()
  const volumes = ref<Volume[]>([])
  const loading = ref(false)

  async function fetchVolumes(): Promise<void> {
    loading.value = true
    try {
      const { data } = await api.get<{ data: Volume[] }>('/volumes')
      volumes.value = data.data
    } finally {
      loading.value = false
    }
  }

  async function triggerScan(id: string): Promise<{ scan_id: string }> {
    const { data } = await api.post<{ data: { scan_id: string; volume_id: string; message: string } }>(
      `/volumes/${id}/scan`,
    )
    return data.data
  }

  return {
    volumes,
    loading,
    fetchVolumes,
    triggerScan,
  }
})
