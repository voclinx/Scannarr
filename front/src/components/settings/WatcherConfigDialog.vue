<script setup lang="ts">
import { ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Checkbox from 'primevue/checkbox'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import type { Watcher, WatcherConfig, WatcherPathMapping } from '@/types'

interface Props {
  visible: boolean
  watcher: Watcher
}

const props = defineProps<Props>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  save: [config: Partial<WatcherConfig>]
}>()

const config = ref<WatcherConfig>(JSON.parse(JSON.stringify(props.watcher.config)))
const newPath = ref('')
const newName = ref('')

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
  if (!p) return
  const exists = config.value.watch_paths.some((wp: WatcherPathMapping) => wp.path === p)
  if (!exists) {
    const name =
      newName.value.trim() ||
      p
        .split('/')
        .filter(Boolean)
        .pop() ||
      p
    config.value.watch_paths = [...config.value.watch_paths, { path: p, name }]
  }
  newPath.value = ''
  newName.value = ''
}

function removePath(index: number) {
  config.value.watch_paths = config.value.watch_paths.filter(
    (_: WatcherPathMapping, i: number) => i !== index,
  )
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
    :style="{ width: '660px' }"
    modal
  >
    <div class="space-y-5">
      <!-- Watch paths -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Chemins surveillés</label>
        <div class="space-y-1 mb-2">
          <div
            v-for="(wp, i) in config.watch_paths"
            :key="wp.path"
            class="flex items-center gap-2 px-2 py-1.5 bg-gray-50 border border-gray-200 rounded text-xs"
          >
            <span class="font-mono flex-1 truncate text-gray-800">{{ wp.path }}</span>
            <span class="text-gray-500 shrink-0 italic">{{ wp.name }}</span>
            <button
              type="button"
              class="text-gray-400 hover:text-red-500 shrink-0"
              @click="removePath(i)"
            >
              <i class="pi pi-times text-xs"></i>
            </button>
          </div>
          <div v-if="config.watch_paths.length === 0" class="text-sm text-gray-400 italic">
            Aucun chemin configuré
          </div>
        </div>
        <div class="flex gap-2">
          <InputText
            v-model="newPath"
            placeholder="/mnt/media/movies"
            class="flex-1 text-sm font-mono"
            @keydown="handleKeydown"
          />
          <InputText
            v-model="newName"
            placeholder="Nom (optionnel)"
            class="w-40 text-sm"
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

      <!-- Scan on start + Disable deletion -->
      <div class="flex flex-wrap items-center gap-6">
        <div class="flex items-center gap-3">
          <Checkbox v-model="config.scan_on_start" :binary="true" inputId="scan_on_start" />
          <label for="scan_on_start" class="text-sm text-gray-700">Scanner au démarrage</label>
        </div>
        <div class="flex items-center gap-3">
          <Checkbox
            v-model="config.disable_deletion"
            :binary="true"
            inputId="disable_deletion"
          />
          <label for="disable_deletion" class="text-sm text-gray-700">
            Désactiver les suppressions
          </label>
        </div>
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
            Délai reconnexion (s)
          </label>
          <InputNumber
            v-model="config.ws_reconnect_delay_seconds"
            :min="1"
            :max="300"
            class="w-full"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Intervalle ping (s)
          </label>
          <InputNumber
            v-model="config.ws_ping_interval_seconds"
            :min="5"
            :max="600"
            class="w-full"
          />
        </div>
      </div>

      <!-- Log retention -->
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Rétention logs (jours)
          </label>
          <InputNumber v-model="config.log_retention_days" :min="1" :max="365" class="w-full" />
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
