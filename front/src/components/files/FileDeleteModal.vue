<script setup lang="ts">
import { ref, computed } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Message from 'primevue/message'
import type { MediaFile } from '@/types'

const props = defineProps<{
  visible: boolean
  file: MediaFile | null
}>()

const emit = defineEmits<{
  (e: 'update:visible', value: boolean): void
  (e: 'confirm', options: { delete_physical: boolean; delete_radarr_reference: boolean }): void
}>()

const deletePhysical = ref(true)
const deleteRadarrRef = ref(false)
const loading = ref(false)

const dialogVisible = computed({
  get: () => props.visible,
  set: (val: boolean) => emit('update:visible', val),
})

function formatSize(bytes: number): string {
  if (bytes === 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  const k = 1024
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return (bytes / Math.pow(k, i)).toFixed(i > 1 ? 1 : 0) + ' ' + units[i]
}

async function handleConfirm(): Promise<void> {
  loading.value = true
  try {
    emit('confirm', {
      delete_physical: deletePhysical.value,
      delete_radarr_reference: deleteRadarrRef.value,
    })
  } finally {
    loading.value = false
    // Reset options for next time
    deletePhysical.value = true
    deleteRadarrRef.value = false
  }
}

function handleCancel(): void {
  dialogVisible.value = false
  deletePhysical.value = true
  deleteRadarrRef.value = false
}
</script>

<template>
  <Dialog
    v-model:visible="dialogVisible"
    :modal="true"
    header="Supprimer le fichier"
    :style="{ width: '500px' }"
    :closable="!loading"
  >
    <div v-if="file" class="space-y-4">
      <Message severity="warn" :closable="false">
        Vous allez supprimer ce fichier. Cette action est irr&eacute;versible.
      </Message>

      <div class="bg-gray-50 rounded-lg p-3">
        <div class="font-medium text-gray-900 break-all">{{ file.file_name }}</div>
        <div class="text-sm text-gray-500 mt-1 break-all">{{ file.file_path }}</div>
        <div class="flex gap-4 mt-2 text-sm text-gray-600">
          <span><i class="pi pi-database mr-1"></i>{{ formatSize(file.file_size_bytes) }}</span>
          <span><i class="pi pi-link mr-1"></i>{{ file.hardlink_count }} hardlink(s)</span>
          <span><i class="pi pi-server mr-1"></i>{{ file.volume_name }}</span>
        </div>
      </div>

      <div v-if="file.hardlink_count > 1" class="mt-2">
        <Message severity="info" :closable="false">
          Ce fichier a {{ file.hardlink_count }} hardlinks. La suppression physique ne
          lib&eacute;rera pas d'espace disque.
        </Message>
      </div>

      <div class="space-y-3 mt-4">
        <div class="flex items-center gap-2">
          <Checkbox v-model="deletePhysical" :binary="true" inputId="deletePhysical" />
          <label for="deletePhysical" class="cursor-pointer">
            Supprimer le fichier physique du disque
          </label>
        </div>

        <div class="flex items-center gap-2" v-if="file.is_linked_radarr">
          <Checkbox v-model="deleteRadarrRef" :binary="true" inputId="deleteRadarrRef" />
          <label for="deleteRadarrRef" class="cursor-pointer">
            Supprimer la r&eacute;f&eacute;rence dans Radarr
          </label>
        </div>
      </div>
    </div>

    <template #footer>
      <div class="flex justify-end gap-2">
        <Button label="Annuler" severity="secondary" text @click="handleCancel" :disabled="loading" />
        <Button
          label="Supprimer"
          severity="danger"
          icon="pi pi-trash"
          @click="handleConfirm"
          :loading="loading"
        />
      </div>
    </template>
  </Dialog>
</template>
