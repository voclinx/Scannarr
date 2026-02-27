<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Select from 'primevue/select'
import { useWatchersStore } from '@/stores/watchers'
import type { WatcherLog } from '@/types'

interface Props {
  visible: boolean
  watcherId: string
  watcherName: string
}

const props = defineProps<Props>()
const emit = defineEmits<{
  'update:visible': [value: boolean]
}>()

const store = useWatchersStore()
const logs = ref<WatcherLog[]>([])
const total = ref(0)
const loading = ref(false)
const selectedLevel = ref<string>('')
const refreshTimer = ref<ReturnType<typeof setInterval> | null>(null)

const levelOptions = [
  { label: 'Tous niveaux', value: '' },
  { label: 'Error', value: 'error' },
  { label: 'Warn', value: 'warn' },
  { label: 'Info', value: 'info' },
  { label: 'Debug', value: 'debug' },
]

function levelClass(level: string): string {
  switch (level.toLowerCase()) {
    case 'error':
      return 'text-red-400'
    case 'warn':
      return 'text-yellow-400'
    case 'info':
      return 'text-blue-400'
    case 'debug':
      return 'text-gray-500'
    default:
      return 'text-gray-300'
  }
}

function formatDate(iso: string): string {
  try {
    return new Date(iso).toLocaleString('fr-FR', {
      day: '2-digit',
      month: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    })
  } catch {
    return iso
  }
}

async function fetchLogs() {
  if (!props.watcherId) return
  loading.value = true
  try {
    const params: Record<string, unknown> = { limit: 500, offset: 0 }
    if (selectedLevel.value) params.level = selectedLevel.value
    const result = await store.fetchLogs(props.watcherId, params)
    logs.value = result.logs
    total.value = result.total
  } catch (e) {
    console.error('Failed to fetch watcher logs:', e)
    logs.value = []
    total.value = 0
  } finally {
    loading.value = false
  }
}

function startAutoRefresh() {
  refreshTimer.value = setInterval(fetchLogs, 5000)
}

function stopAutoRefresh() {
  if (refreshTimer.value !== null) {
    clearInterval(refreshTimer.value)
    refreshTimer.value = null
  }
}

watch(
  () => props.visible,
  (v) => {
    if (v) {
      fetchLogs()
      startAutoRefresh()
    } else {
      stopAutoRefresh()
    }
  },
)

watch(selectedLevel, () => {
  fetchLogs()
})

onMounted(() => {
  if (props.visible) {
    fetchLogs()
    startAutoRefresh()
  }
})

onUnmounted(() => {
  stopAutoRefresh()
})
</script>

<template>
  <Dialog
    :visible="visible"
    @update:visible="emit('update:visible', $event)"
    :header="`Logs — ${watcherName}`"
    :style="{ width: '900px' }"
    modal
  >
    <!-- Toolbar -->
    <div class="flex items-center gap-3 mb-3">
      <Select
        v-model="selectedLevel"
        :options="levelOptions"
        optionLabel="label"
        optionValue="value"
        placeholder="Tous niveaux"
        class="w-40"
      />
      <Button
        icon="pi pi-refresh"
        severity="secondary"
        size="small"
        :loading="loading"
        @click="fetchLogs"
        label="Actualiser"
      />
      <span class="text-sm text-gray-500 ml-auto">
        {{ logs.length }} / {{ total }} entrées
      </span>
    </div>

    <!-- Console -->
    <div
      class="bg-gray-900 rounded-lg p-4 font-mono text-xs overflow-y-auto"
      style="height: 480px"
    >
      <div v-if="logs.length === 0" class="text-gray-500 italic">
        Aucun log disponible.
      </div>
      <div
        v-for="log in logs"
        :key="log.id"
        class="flex gap-3 py-0.5 hover:bg-gray-800 px-1 rounded"
      >
        <span class="text-gray-600 shrink-0 w-36">{{ formatDate(log.created_at) }}</span>
        <span :class="['uppercase font-bold shrink-0 w-10', levelClass(log.level)]">
          {{ log.level }}
        </span>
        <span class="text-gray-200 break-all">{{ log.message }}</span>
        <span
          v-if="log.context && Object.keys(log.context).length"
          class="text-gray-500 ml-2 shrink-0"
          :title="JSON.stringify(log.context, null, 2)"
        >
          { {{ Object.keys(log.context).join(', ') }} }
        </span>
      </div>
    </div>

    <template #footer>
      <Button label="Fermer" severity="secondary" @click="emit('update:visible', false)" />
    </template>
  </Dialog>
</template>
