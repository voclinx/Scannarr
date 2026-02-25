<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useVolumesStore } from '@/stores/volumes'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import type { Volume } from '@/types'

const store = useVolumesStore()

const showFormDialog = ref(false)
const editingVolume = ref<Volume | null>(null)
const formName = ref('')
const formPath = ref('')
const formHostPath = ref('')
const formType = ref<'local' | 'network'>('local')
const formLoading = ref(false)
const formError = ref<string | null>(null)
const scanLoading = ref<Record<string, boolean>>({})

const typeOptions = [
  { label: 'Local', value: 'local' },
  { label: 'Réseau', value: 'network' },
]

function formatSize(bytes: number | undefined): string {
  if (!bytes || bytes === 0) return '—'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  const k = 1024
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return (bytes / Math.pow(k, i)).toFixed(i > 1 ? 1 : 0) + ' ' + units[i]
}

function openAddDialog(): void {
  editingVolume.value = null
  formName.value = ''
  formPath.value = ''
  formHostPath.value = ''
  formType.value = 'local'
  formError.value = null
  showFormDialog.value = true
}

function openEditDialog(volume: Volume): void {
  editingVolume.value = volume
  formName.value = volume.name
  formPath.value = volume.path
  formHostPath.value = volume.host_path
  formType.value = volume.type
  formError.value = null
  showFormDialog.value = true
}

async function handleSave(): Promise<void> {
  formLoading.value = true
  formError.value = null

  try {
    const payload = {
      name: formName.value,
      path: formPath.value,
      host_path: formHostPath.value,
      type: formType.value,
    }

    if (editingVolume.value) {
      await store.updateVolume(editingVolume.value.id, payload)
    } else {
      await store.createVolume(payload)
    }
    showFormDialog.value = false
    await store.fetchVolumes()
  } catch (err: unknown) {
    const error = err as { response?: { data?: { error?: { message?: string } } } }
    formError.value = error.response?.data?.error?.message || 'Erreur lors de la sauvegarde'
  } finally {
    formLoading.value = false
  }
}

async function handleDelete(volume: Volume): Promise<void> {
  if (!confirm(`Supprimer le volume ${volume.name} ?`)) return
  await store.deleteVolume(volume.id)
}

const scanError = ref<string | null>(null)
const scanSuccess = ref<string | null>(null)

async function handleScan(volume: Volume): Promise<void> {
  scanLoading.value[volume.id] = true
  scanError.value = null
  scanSuccess.value = null
  try {
    await store.triggerScan(volume.id)
    scanSuccess.value = `Scan lancé pour le volume "${volume.name}"`
    setTimeout(() => { scanSuccess.value = null }, 5000)
  } catch (err: unknown) {
    const error = err as { response?: { data?: { error?: { message?: string } } } }
    scanError.value = error.response?.data?.error?.message || `Erreur lors du scan du volume "${volume.name}"`
  } finally {
    scanLoading.value[volume.id] = false
  }
}

onMounted(() => {
  store.fetchVolumes()
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h3 class="text-lg font-semibold text-gray-900">Volumes</h3>
      <Button label="Ajouter" icon="pi pi-plus" size="small" @click="openAddDialog" />
    </div>

    <Message v-if="scanSuccess" severity="success" :closable="false">{{ scanSuccess }}</Message>
    <Message v-if="scanError" severity="error" :closable="false">{{ scanError }}</Message>

    <DataTable
      :value="store.volumes"
      dataKey="id"
      stripedRows
      scrollable
      scrollHeight="flex"
      class="text-sm"
    >
      <template #empty>
        <div class="text-center py-4 text-gray-500">Aucun volume configuré</div>
      </template>

      <Column field="name" header="Nom" style="min-width: 120px" />
      <Column field="path" header="Chemin Docker" style="min-width: 140px">
        <template #body="{ data }: { data: Volume }">
          <span class="text-gray-600 text-xs font-mono truncate block max-w-[200px]" :title="data.path">{{ data.path }}</span>
        </template>
      </Column>
      <Column field="host_path" header="Chemin hôte" style="min-width: 140px">
        <template #body="{ data }: { data: Volume }">
          <span class="text-gray-600 text-xs font-mono truncate block max-w-[200px]" :title="data.host_path">{{ data.host_path }}</span>
        </template>
      </Column>
      <Column header="Type" style="width: 80px">
        <template #body="{ data }: { data: Volume }">
          <Tag :value="data.type" :severity="data.type === 'local' ? 'info' : 'warn'" />
        </template>
      </Column>
      <Column header="Statut" style="width: 80px">
        <template #body="{ data }: { data: Volume }">
          <Tag
            :value="data.status"
            :severity="data.status === 'active' ? 'success' : data.status === 'error' ? 'danger' : 'secondary'"
          />
        </template>
      </Column>
      <Column header="Espace" style="min-width: 130px">
        <template #body="{ data }: { data: Volume }">
          <span class="text-xs text-gray-500 whitespace-nowrap">{{ formatSize(data.used_space_bytes) }} / {{ formatSize(data.total_space_bytes) }}</span>
        </template>
      </Column>
      <Column header="Actions" frozen alignFrozen="right" style="width: 110px">
        <template #body="{ data }: { data: Volume }">
          <div class="flex gap-1">
            <Button
              icon="pi pi-refresh"
              size="small"
              text
              rounded
              v-tooltip.top="'Scanner'"
              :loading="scanLoading[data.id]"
              @click="handleScan(data)"
            />
            <Button icon="pi pi-pencil" size="small" text rounded v-tooltip.top="'Modifier'" @click="openEditDialog(data)" />
            <Button icon="pi pi-trash" size="small" text rounded severity="danger" v-tooltip.top="'Supprimer'" @click="handleDelete(data)" />
          </div>
        </template>
      </Column>
    </DataTable>

    <!-- Add/Edit Dialog -->
    <Dialog
      v-model:visible="showFormDialog"
      :modal="true"
      :header="editingVolume ? 'Modifier le volume' : 'Ajouter un volume'"
      :style="{ width: '500px' }"
    >
      <div class="space-y-4">
        <Message v-if="formError" severity="error" :closable="false">{{ formError }}</Message>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Nom</label>
          <InputText v-model="formName" placeholder="Ex: NAS Principal" class="w-full" />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Chemin Docker</label>
          <InputText v-model="formPath" placeholder="/mnt/volume1" class="w-full" />
          <p class="text-xs text-gray-400 mt-1">Chemin dans le conteneur Docker</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Chemin hôte</label>
          <InputText v-model="formHostPath" placeholder="/mnt/media1" class="w-full" />
          <p class="text-xs text-gray-400 mt-1">Chemin réel sur le serveur (utilisé par le watcher)</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
          <Select v-model="formType" :options="typeOptions" optionLabel="label" optionValue="value" class="w-full" />
        </div>
      </div>

      <template #footer>
        <div class="flex justify-end gap-2">
          <Button label="Annuler" severity="secondary" text @click="showFormDialog = false" />
          <Button :label="editingVolume ? 'Modifier' : 'Ajouter'" @click="handleSave" :loading="formLoading" />
        </div>
      </template>
    </Dialog>
  </div>
</template>
