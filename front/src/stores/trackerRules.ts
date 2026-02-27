import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { TrackerRule } from '@/types'
import { useApi } from '@/composables/useApi'

export const useTrackerRulesStore = defineStore('trackerRules', () => {
  const api = useApi()
  const rules = ref<TrackerRule[]>([])
  const loading = ref(false)

  async function fetchRules(): Promise<void> {
    loading.value = true
    try {
      const { data } = await api.get<{ data: TrackerRule[] }>('/tracker-rules')
      rules.value = data.data
    } finally {
      loading.value = false
    }
  }

  async function createRule(payload: Omit<TrackerRule, 'id' | 'is_auto_detected'>): Promise<void> {
    await api.post('/tracker-rules', payload)
    await fetchRules()
  }

  async function updateRule(id: string, payload: Partial<TrackerRule>): Promise<void> {
    await api.put(`/tracker-rules/${id}`, payload)
    await fetchRules()
  }

  async function deleteRule(id: string): Promise<void> {
    await api.delete(`/tracker-rules/${id}`)
    rules.value = rules.value.filter((r) => r.id !== id)
  }

  return { rules, loading, fetchRules, createRule, updateRule, deleteRule }
})
