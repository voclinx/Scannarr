<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useSettingsStore } from '@/stores/settings'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Checkbox from 'primevue/checkbox'
import type { MediaPlayerInstance } from '@/types'

const store = useSettingsStore()

const showFormDialog = ref(false)
const editingPlayer = ref<MediaPlayerInstance | null>(null)
const formName = ref('')
const formType = ref<'plex' | 'jellyfin'>('plex')
const formUrl = ref('')
const formToken = ref('')
const formActive = ref(true)
const formLoading = ref(false)
const formError = ref<string | null>(null)

const typeOptions = [
  { label: 'Plex', value: 'plex' },
  { label: 'Jellyfin', value: 'jellyfin' },
]

const testResults = ref<Record<string, { success: boolean; message: string }>>({})

function openAddDialog(): void {
  editingPlayer.value = null
  formName.value = ''
  formType.value = 'plex'
  formUrl.value = ''
  formToken.value = ''
  formActive.value = true
  formError.value = null
  showFormDialog.value = true
}

function openEditDialog(player: MediaPlayerInstance): void {
  editingPlayer.value = player
  formName.value = player.name
  formType.value = player.type
  formUrl.value = player.url
  formToken.value = player.token
  formActive.value = player.is_active
  formError.value = null
  showFormDialog.value = true
}

async function handleSave(): Promise<void> {
  formLoading.value = true
  formError.value = null

  try {
    if (editingPlayer.value) {
      await store.updateMediaPlayer(editingPlayer.value.id, {
        name: formName.value,
        type: formType.value,
        url: formUrl.value,
        token: formToken.value,
        is_active: formActive.value,
      })
    } else {
      await store.createMediaPlayer({
        name: formName.value,
        type: formType.value,
        url: formUrl.value,
        token: formToken.value,
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

async function handleDelete(player: MediaPlayerInstance): Promise<void> {
  if (!confirm(`Supprimer le lecteur ${player.name} ?`)) return
  await store.deleteMediaPlayer(player.id)
}

async function handleTest(player: MediaPlayerInstance): Promise<void> {
  testResults.value[player.id] = { success: false, message: 'Test en cours...' }
  const result = await store.testMediaPlayerConnection(player.id)

  if (result.success) {
    testResults.value[player.id] = {
      success: true,
      message: `${result.name} v${result.version}`,
    }
  } else {
    testResults.value[player.id] = {
      success: false,
      message: result.error || 'Échec de la connexion',
    }
  }
}

onMounted(() => {
  store.fetchMediaPlayers()
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h3 class="text-lg font-semibold text-gray-900">Lecteurs multimédias</h3>
      <Button label="Ajouter" icon="pi pi-plus" size="small" @click="openAddDialog" />
    </div>

    <DataTable
      :value="store.mediaPlayers"
      :loading="store.mediaPlayersLoading"
      dataKey="id"
      stripedRows
      class="text-sm"
    >
      <template #empty>
        <div class="text-center py-4 text-gray-500">
          Aucun lecteur multimédia configuré
        </div>
      </template>

      <Column field="name" header="Nom" style="min-width: 150px" />
      <Column field="type" header="Type" style="width: 100px">
        <template #body="{ data }: { data: MediaPlayerInstance }">
          <Tag
            :value="data.type === 'plex' ? 'Plex' : 'Jellyfin'"
            :severity="data.type === 'plex' ? 'warn' : 'info'"
          />
        </template>
      </Column>
      <Column field="url" header="URL" style="min-width: 200px">
        <template #body="{ data }: { data: MediaPlayerInstance }">
          <span class="text-gray-600">{{ data.url }}</span>
        </template>
      </Column>
      <Column header="Statut" style="width: 80px">
        <template #body="{ data }: { data: MediaPlayerInstance }">
          <Tag :value="data.is_active ? 'Actif' : 'Inactif'" :severity="data.is_active ? 'success' : 'secondary'" />
        </template>
      </Column>
      <Column header="Test" style="width: 200px">
        <template #body="{ data }: { data: MediaPlayerInstance }">
          <div class="flex items-center gap-2">
            <Button icon="pi pi-bolt" size="small" text rounded @click="handleTest(data)" />
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
        <template #body="{ data }: { data: MediaPlayerInstance }">
          <div class="flex gap-1">
            <Button icon="pi pi-pencil" size="small" text rounded @click="openEditDialog(data)" />
            <Button icon="pi pi-trash" size="small" text rounded severity="danger" @click="handleDelete(data)" />
          </div>
        </template>
      </Column>
    </DataTable>

    <!-- Add/Edit Dialog -->
    <Dialog
      v-model:visible="showFormDialog"
      :modal="true"
      :header="editingPlayer ? 'Modifier le lecteur' : 'Ajouter un lecteur'"
      :style="{ width: '450px' }"
    >
      <div class="space-y-4">
        <Message v-if="formError" severity="error" :closable="false">{{ formError }}</Message>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Nom</label>
          <InputText v-model="formName" placeholder="Ex: Plex principal" class="w-full" />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
          <Select
            v-model="formType"
            :options="typeOptions"
            optionLabel="label"
            optionValue="value"
            class="w-full"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">URL</label>
          <InputText v-model="formUrl" placeholder="http://192.168.1.10:32400" class="w-full" />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Token</label>
          <InputText v-model="formToken" placeholder="Token d'accès" class="w-full" />
        </div>

        <div class="flex items-center gap-2">
          <Checkbox v-model="formActive" :binary="true" inputId="playerFormActive" />
          <label for="playerFormActive" class="cursor-pointer text-sm">Actif</label>
        </div>
      </div>

      <template #footer>
        <div class="flex justify-end gap-2">
          <Button label="Annuler" severity="secondary" text @click="showFormDialog = false" />
          <Button
            :label="editingPlayer ? 'Modifier' : 'Ajouter'"
            @click="handleSave"
            :loading="formLoading"
          />
        </div>
      </template>
    </Dialog>
  </div>
</template>
