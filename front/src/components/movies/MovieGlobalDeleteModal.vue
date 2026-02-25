<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Message from 'primevue/message'
import type { MovieDetail } from '@/types'

const props = defineProps<{
  visible: boolean
  movie: MovieDetail | null
}>()

const emit = defineEmits<{
  (
    e: 'update:visible',
    value: boolean,
  ): void
  (
    e: 'confirm',
    options: {
      file_ids: string[]
      delete_radarr_reference: boolean
      delete_media_player_reference: boolean
      disable_radarr_auto_search: boolean
    },
  ): void
}>()

const selectedFileIds = ref<string[]>([])
const deleteRadarrRef = ref(false)
const deleteMediaPlayerRef = ref(false)
const disableAutoSearch = ref(false)
const loading = ref(false)

const dialogVisible = computed({
  get: () => props.visible,
  set: (val: boolean) => emit('update:visible', val),
})

// Select all files by default when modal opens
watch(
  () => props.visible,
  (visible) => {
    if (visible && props.movie) {
      selectedFileIds.value = props.movie.files.map((f) => f.id)
      deleteRadarrRef.value = false
      deleteMediaPlayerRef.value = false
      disableAutoSearch.value = false
    }
  },
)

function formatSize(bytes: number): string {
  if (bytes === 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  const k = 1024
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return (bytes / Math.pow(k, i)).toFixed(i > 1 ? 1 : 0) + ' ' + units[i]
}

function toggleFile(fileId: string): void {
  const idx = selectedFileIds.value.indexOf(fileId)
  if (idx >= 0) {
    selectedFileIds.value.splice(idx, 1)
  } else {
    selectedFileIds.value.push(fileId)
  }
}

function toggleAll(): void {
  if (!props.movie) return
  if (selectedFileIds.value.length === props.movie.files.length) {
    selectedFileIds.value = []
  } else {
    selectedFileIds.value = props.movie.files.map((f) => f.id)
  }
}

const allSelected = computed(() => {
  if (!props.movie || props.movie.files.length === 0) return false
  return selectedFileIds.value.length === props.movie.files.length
})

const totalSelectedSize = computed(() => {
  if (!props.movie) return 0
  return props.movie.files
    .filter((f) => selectedFileIds.value.includes(f.id))
    .reduce((sum, f) => sum + f.file_size_bytes, 0)
})

async function handleConfirm(): Promise<void> {
  loading.value = true
  try {
    emit('confirm', {
      file_ids: selectedFileIds.value,
      delete_radarr_reference: deleteRadarrRef.value,
      delete_media_player_reference: deleteMediaPlayerRef.value,
      disable_radarr_auto_search: disableAutoSearch.value,
    })
  } finally {
    loading.value = false
  }
}

function handleCancel(): void {
  dialogVisible.value = false
}
</script>

<template>
  <Dialog
    v-model:visible="dialogVisible"
    :modal="true"
    header="Suppression globale du film"
    :style="{ width: '700px' }"
    :closable="!loading"
  >
    <div v-if="movie" class="space-y-4">
      <Message severity="warn" :closable="false">
        Vous allez supprimer des fichiers liés à
        <strong>{{ movie.title }}</strong>
        <span v-if="movie.year"> ({{ movie.year }})</span>.
        Cette action est irréversible.
      </Message>

      <!-- File selection -->
      <div>
        <div class="flex items-center justify-between mb-2">
          <span class="text-sm font-medium text-gray-700">
            Fichiers à supprimer ({{ selectedFileIds.length }}/{{ movie.files.length }})
          </span>
          <Button
            :label="allSelected ? 'Tout désélectionner' : 'Tout sélectionner'"
            size="small"
            text
            @click="toggleAll"
          />
        </div>

        <div class="border border-gray-200 rounded-lg max-h-60 overflow-y-auto">
          <div
            v-for="file in movie.files"
            :key="file.id"
            class="flex items-center gap-3 p-2 border-b border-gray-100 last:border-0 hover:bg-gray-50 cursor-pointer"
            @click="toggleFile(file.id)"
          >
            <Checkbox
              :modelValue="selectedFileIds.includes(file.id)"
              :binary="true"
              @update:modelValue="toggleFile(file.id)"
            />
            <div class="flex-1 min-w-0">
              <div class="text-sm text-gray-900 truncate">{{ file.file_name }}</div>
              <div class="text-xs text-gray-400">
                {{ file.volume_name }} · {{ formatSize(file.file_size_bytes) }}
                <span v-if="file.resolution"> · {{ file.resolution }}</span>
                <span v-if="file.hardlink_count > 1">
                  · {{ file.hardlink_count }} hardlinks
                </span>
              </div>
            </div>
          </div>
        </div>

        <div class="text-sm text-gray-500 mt-1">
          Espace libéré estimé : {{ formatSize(totalSelectedSize) }}
        </div>
      </div>

      <!-- Options -->
      <div class="space-y-3 pt-2 border-t border-gray-200">
        <div class="flex items-center gap-2" v-if="movie.radarr_instance">
          <Checkbox v-model="deleteRadarrRef" :binary="true" inputId="deleteRadarrRef" />
          <label for="deleteRadarrRef" class="cursor-pointer text-sm">
            Supprimer la référence dans Radarr
          </label>
        </div>

        <div class="flex items-center gap-2" v-if="movie.radarr_instance">
          <Checkbox v-model="disableAutoSearch" :binary="true" inputId="disableAutoSearch" />
          <label for="disableAutoSearch" class="cursor-pointer text-sm">
            Désactiver la recherche automatique Radarr
          </label>
        </div>

        <div class="flex items-center gap-2">
          <Checkbox
            v-model="deleteMediaPlayerRef"
            :binary="true"
            inputId="deleteMediaPlayerRef"
          />
          <label for="deleteMediaPlayerRef" class="cursor-pointer text-sm">
            Supprimer la référence dans le lecteur multimédia
          </label>
        </div>
      </div>

      <!-- Auto-search warning -->
      <Message
        v-if="!disableAutoSearch && movie.radarr_monitored && movie.radarr_instance && !deleteRadarrRef"
        severity="info"
        :closable="false"
      >
        La recherche automatique Radarr est active. Le film pourrait être re-téléchargé.
      </Message>
    </div>

    <template #footer>
      <div class="flex justify-end gap-2">
        <Button
          label="Annuler"
          severity="secondary"
          text
          @click="handleCancel"
          :disabled="loading"
        />
        <Button
          label="Supprimer"
          severity="danger"
          icon="pi pi-trash"
          @click="handleConfirm"
          :loading="loading"
          :disabled="selectedFileIds.length === 0"
        />
      </div>
    </template>
  </Dialog>
</template>
