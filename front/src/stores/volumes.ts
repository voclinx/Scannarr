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

  async function createVolume(payload: {
    name: string
    path: string
    host_path: string
    type: 'local' | 'network'
  }): Promise<Volume> {
    const { data } = await api.post<{ data: Volume }>('/volumes', payload)
    volumes.value.push(data.data)
    return data.data
  }

  async function updateVolume(
    id: string,
    payload: Partial<Pick<Volume, 'name' | 'path' | 'host_path' | 'type' | 'status'>>,
  ): Promise<Volume> {
    const { data } = await api.put<{ data: Volume }>(`/volumes/${id}`, payload)
    const index = volumes.value.findIndex((v) => v.id === id)
    if (index !== -1) {
      volumes.value[index] = data.data
    }
    return data.data
  }

  async function deleteVolume(id: string): Promise<void> {
    await api.delete(`/volumes/${id}`)
    volumes.value = volumes.value.filter((v) => v.id !== id)
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
    createVolume,
    updateVolume,
    deleteVolume,
    triggerScan,
  }
})
