<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useSettingsStore } from '@/stores/settings'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'

const store = useSettingsStore()

const qbittorrentUrl = ref('')
const qbittorrentUsername = ref('')
const qbittorrentPassword = ref('')

const saving = ref(false)
const saveSuccess = ref(false)
const saveError = ref<string | null>(null)
const loadError = ref<string | null>(null)

async function handleSave(): Promise<void> {
  saving.value = true
  saveError.value = null
  saveSuccess.value = false

  try {
    const updates: Record<string, string | null> = {
      qbittorrent_url: qbittorrentUrl.value || null,
      qbittorrent_username: qbittorrentUsername.value || null,
    }

    // Only send password if user entered something
    if (qbittorrentPassword.value) {
      updates['qbittorrent_password'] = qbittorrentPassword.value
    }

    await store.updateSettings(updates)
    saveSuccess.value = true
    setTimeout(() => {
      saveSuccess.value = false
    }, 3000)
  } catch (err: unknown) {
    const error = err as { response?: { data?: { error?: { message?: string } } } }
    saveError.value = error.response?.data?.error?.message || 'Erreur lors de la sauvegarde'
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  try {
    await store.fetchSettings()
    qbittorrentUrl.value = store.settings['qbittorrent_url'] ?? ''
    qbittorrentUsername.value = store.settings['qbittorrent_username'] ?? ''
    qbittorrentPassword.value = ''
  } catch (err: unknown) {
    const error = err as { response?: { data?: { error?: { message?: string } } } }
    loadError.value = error.response?.data?.error?.message || 'Erreur lors du chargement des paramètres'
  }
})
</script>

<template>
  <div class="space-y-4">
    <h3 class="text-lg font-semibold text-gray-900">qBittorrent</h3>

    <Message v-if="loadError" severity="error" :closable="true" @close="loadError = null">
      {{ loadError }}
    </Message>
    <Message v-if="saveSuccess" severity="success" :closable="false">
      Configuration sauvegardée avec succès
    </Message>
    <Message v-if="saveError" severity="error" :closable="false">{{ saveError }}</Message>

    <div class="space-y-3 max-w-xl">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">URL</label>
        <InputText v-model="qbittorrentUrl" placeholder="http://192.168.1.10:8080" class="w-full" />
        <p class="text-xs text-gray-400 mt-1">
          URL de l'interface web de qBittorrent.
        </p>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Nom d'utilisateur</label>
        <InputText v-model="qbittorrentUsername" placeholder="admin" class="w-full" />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Mot de passe</label>
        <InputText v-model="qbittorrentPassword" type="password" placeholder="Laisser vide pour ne pas modifier" class="w-full" />
      </div>
    </div>

    <div class="pt-4">
      <Button label="Sauvegarder" icon="pi pi-save" @click="handleSave" :loading="saving" />
    </div>
  </div>
</template>
