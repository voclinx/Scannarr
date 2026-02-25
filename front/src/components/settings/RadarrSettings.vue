<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useSettingsStore } from '@/stores/settings'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Checkbox from 'primevue/checkbox'
import type { RadarrInstance } from '@/types'

const store = useSettingsStore()

const showFormDialog = ref(false)
const editingInstance = ref<RadarrInstance | null>(null)
const formName = ref('')
const formUrl = ref('')
const formApiKey = ref('')
const formActive = ref(true)
const formLoading = ref(false)
const formError = ref<string | null>(null)

const testResults = ref<Record<string, { success: boolean; message: string }>>({})

function openAddDialog(): void {
  editingInstance.value = null
  formName.value = ''
  formUrl.value = ''
  formApiKey.value = ''
  formActive.value = true
  formError.value = null
  showFormDialog.value = true
}

function openEditDialog(instance: RadarrInstance): void {
  editingInstance.value = instance
  formName.value = instance.name
  formUrl.value = instance.url
  formApiKey.value = instance.api_key
  formActive.value = instance.is_active
  formError.value = null
  showFormDialog.value = true
}

async function handleSave(): Promise<void> {
  formError.value = null

  // Client-side validation
  if (!formName.value.trim()) {
    formError.value = 'Le nom est requis'
    return
  }
  if (!formUrl.value.trim()) {
    formError.value = "L'URL est requise (ex: http://localhost:7878)"
    return
  }
  if (!formApiKey.value.trim()) {
    formError.value = 'La clé API est requise'
    return
  }

  formLoading.value = true

  try {
    if (editingInstance.value) {
      await store.updateRadarrInstance(editingInstance.value.id, {
        name: formName.value,
        url: formUrl.value,
        api_key: formApiKey.value,
        is_active: formActive.value,
      })
    } else {
      await store.createRadarrInstance({
        name: formName.value,
        url: formUrl.value,
        api_key: formApiKey.value,
        is_active: formActive.value,
      })
    }
    showFormDialog.value = false
  } catch (err: unknown) {
    const error = err as { response?: { data?: { error?: { message?: string } } } }
    formError.value = error.response?.data?.error?.message || 'Erreur lors de la sauvegarde'
  } finally {
    formLoading.value = false
  }
}

async function handleDelete(instance: RadarrInstance): Promise<void> {
  if (!confirm(`Supprimer l'instance ${instance.name} ?`)) return
  await store.deleteRadarrInstance(instance.id)
}

async function handleTest(instance: RadarrInstance): Promise<void> {
  testResults.value[instance.id] = { success: false, message: 'Test en cours...' }
  const result = await store.testRadarrConnection(instance.id)

  if (result.success) {
    testResults.value[instance.id] = {
      success: true,
      message: `v${result.version} — ${result.movies_count} films`,
    }
  } else {
    testResults.value[instance.id] = {
      success: false,
      message: result.error || 'Échec de la connexion',
    }
  }
}

onMounted(() => {
  store.fetchRadarrInstances()
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h3 class="text-lg font-semibold text-gray-900">Instances Radarr</h3>
      <Button
        label="Ajouter"
        icon="pi pi-plus"
        size="small"
        @click="openAddDialog"
      />
    </div>

    <DataTable
      :value="store.radarrInstances"
      :loading="store.radarrLoading"
      dataKey="id"
      stripedRows
      class="text-sm"
    >
      <template #empty>
        <div class="text-center py-4 text-gray-500">
          Aucune instance Radarr configurée
        </div>
      </template>

      <Column field="name" header="Nom" style="min-width: 150px" />
      <Column field="url" header="URL" style="min-width: 200px">
        <template #body="{ data }: { data: RadarrInstance }">
          <span class="text-gray-600">{{ data.url }}</span>
        </template>
      </Column>
      <Column header="Statut" style="width: 80px">
        <template #body="{ data }: { data: RadarrInstance }">
          <Tag :value="data.is_active ? 'Actif' : 'Inactif'" :severity="data.is_active ? 'success' : 'secondary'" />
        </template>
      </Column>
      <Column header="Dernière sync" style="width: 150px">
        <template #body="{ data }: { data: RadarrInstance }">
          <span class="text-xs text-gray-500">
            {{ data.last_sync_at ? new Date(data.last_sync_at).toLocaleString('fr-FR') : 'Jamais' }}
          </span>
        </template>
      </Column>
      <Column header="Test" style="width: 200px">
        <template #body="{ data }: { data: RadarrInstance }">
          <div class="flex items-center gap-2">
            <Button
              icon="pi pi-bolt"
              size="small"
              text
              rounded
              v-tooltip.top="'Tester la connexion'"
              @click="handleTest(data)"
            />
            <span
              v-if="testResults[data.id]"
              :class="testResults[data.id]?.success ? 'text-green-600' : 'text-red-600'"
              class="text-xs"
            >
              {{ testResults[data.id]?.message }}
            </span>
          </div>
        </template>
      </Column>
      <Column header="" style="width: 100px">
        <template #body="{ data }: { data: RadarrInstance }">
          <div class="flex gap-1">
            <Button
              icon="pi pi-pencil"
              size="small"
              text
              rounded
              @click="openEditDialog(data)"
            />
            <Button
              icon="pi pi-trash"
              size="small"
              text
              rounded
              severity="danger"
              @click="handleDelete(data)"
            />
          </div>
        </template>
      </Column>
    </DataTable>

    <!-- Add/Edit Dialog -->
    <Dialog
      v-model:visible="showFormDialog"
      :modal="true"
      :header="editingInstance ? 'Modifier l\'instance Radarr' : 'Ajouter une instance Radarr'"
      :style="{ width: '450px' }"
    >
      <div class="space-y-4">
        <Message v-if="formError" severity="error" :closable="false">
          {{ formError }}
        </Message>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Nom</label>
          <InputText v-model="formName" placeholder="Ex: Radarr 4K" class="w-full" />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">URL</label>
          <InputText v-model="formUrl" placeholder="http://192.168.1.10:7878" class="w-full" />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Clé API</label>
          <InputText v-model="formApiKey" placeholder="Clé API Radarr" class="w-full" />
        </div>

        <div class="flex items-center gap-2">
          <Checkbox v-model="formActive" :binary="true" inputId="formActive" />
          <label for="formActive" class="cursor-pointer text-sm">Active</label>
        </div>
      </div>

      <template #footer>
        <div class="flex justify-end gap-2">
          <Button
            label="Annuler"
            severity="secondary"
            text
            @click="showFormDialog = false"
          />
          <Button
            :label="editingInstance ? 'Modifier' : 'Ajouter'"
            @click="handleSave"
            :loading="formLoading"
          />
        </div>
      </template>
    </Dialog>
  </div>
</template>
