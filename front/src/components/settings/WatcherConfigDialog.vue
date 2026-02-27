<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Checkbox from 'primevue/checkbox'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import type { Watcher, WatcherConfig } from '@/types'

interface Props {
  visible: boolean
  watcher: Watcher
}

const props = defineProps<Props>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  save: [config: Partial<WatcherConfig>]
}>()

const config = ref<WatcherConfig>({ ...props.watcher.config })
const newPath = ref('')

watch(
  () => props.watcher,
  (w) => {
    config.value = JSON.parse(JSON.stringify(w.config))
  },
)

const logLevelOptions = [
  { label: 'Debug', value: 'debug' },
  { label: 'Info', value: 'info' },
  { label: 'Warn', value: 'warn' },
  { label: 'Error', value: 'error' },
]

function addPath() {
  const p = newPath.value.trim()
  if (p && !config.value.watch_paths.includes(p)) {
    config.value.watch_paths = [...config.value.watch_paths, p]
  }
  newPath.value = ''
}

function removePath(path: string) {
  config.value.watch_paths = config.value.watch_paths.filter((p) => p !== path)
}

function handleKeydown(e: KeyboardEvent) {
  if (e.key === 'Enter') {
    e.preventDefault()
    addPath()
  }
}

function save() {
  emit('save', { ...config.value })
  emit('update:visible', false)
}
</script>

<template>
  <Dialog
    :visible="visible"
    @update:visible="emit('update:visible', $event)"
    :header="`Configuration — ${watcher.name || watcher.watcher_id}`"
    :style="{ width: '600px' }"
    modal
  >
    <div class="space-y-5">
      <!-- Watch paths -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Chemins surveillés</label>
        <div class="flex flex-wrap gap-2 mb-2">
          <span
            v-for="path in config.watch_paths"
            :key="path"
            class="inline-flex items-center gap-1 px-2 py-1 bg-gray-100 text-gray-800 text-xs rounded font-mono"
          >
            {{ path }}
            <button
              type="button"
              class="ml-1 text-gray-500 hover:text-red-500"
              @click="removePath(path)"
            >
              <i class="pi pi-times text-xs"></i>
            </button>
          </span>
          <span v-if="config.watch_paths.length === 0" class="text-sm text-gray-400 italic">
            Aucun chemin configuré
          </span>
        </div>
        <div class="flex gap-2">
          <InputText
            v-model="newPath"
            placeholder="/mnt/media/movies"
            class="flex-1 text-sm font-mono"
            @keydown="handleKeydown"
          />
          <Button
            icon="pi pi-plus"
            severity="secondary"
            size="small"
            :disabled="!newPath.trim()"
            @click="addPath"
          />
        </div>
      </div>

      <!-- Scan on start -->
      <div class="flex items-center gap-3">
        <Checkbox v-model="config.scan_on_start" :binary="true" inputId="scan_on_start" />
        <label for="scan_on_start" class="text-sm text-gray-700">
          Scanner au démarrage
        </label>
      </div>

      <!-- Log level -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Niveau de log</label>
        <Select
          v-model="config.log_level"
          :options="logLevelOptions"
          optionLabel="label"
          optionValue="value"
          class="w-full"
        />
      </div>

      <!-- Reconnect delay + Ping interval -->
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Délai reconnexion
          </label>
          <InputText
            v-model="config.reconnect_delay"
            placeholder="5s"
            class="w-full text-sm font-mono"
          />
          <p class="text-xs text-gray-500 mt-1">ex: 5s, 30s, 1m</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Intervalle ping
          </label>
          <InputText
            v-model="config.ping_interval"
            placeholder="30s"
            class="w-full text-sm font-mono"
          />
          <p class="text-xs text-gray-500 mt-1">ex: 30s, 1m</p>
        </div>
      </div>

      <!-- Log retention -->
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Rétention logs (jours)
          </label>
          <InputNumber
            v-model="config.log_retention_days"
            :min="1"
            :max="365"
            class="w-full"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Rétention debug (heures)
          </label>
          <InputNumber
            v-model="config.debug_log_retention_hours"
            :min="1"
            :max="720"
            class="w-full"
          />
        </div>
      </div>
    </div>

    <template #footer>
      <Button
        label="Annuler"
        severity="secondary"
        @click="emit('update:visible', false)"
      />
      <Button label="Enregistrer" icon="pi pi-check" @click="save" />
    </template>
  </Dialog>
</template>
