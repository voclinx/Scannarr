<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useSettingsStore } from '@/stores/settings'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'

const store = useSettingsStore()

const tmdbApiKey = ref('')
const discordWebhookUrl = ref('')
const discordReminderDays = ref('3')
const qbittorrentUrl = ref('')
const qbittorrentUsername = ref('')
const qbittorrentPassword = ref('')

const saving = ref(false)
const successMessage = ref<string | null>(null)
const errorMessage = ref<string | null>(null)

function loadFromStore(): void {
  tmdbApiKey.value = store.settings['tmdb_api_key'] ?? ''
  discordWebhookUrl.value = store.settings['discord_webhook_url'] ?? ''
  discordReminderDays.value = store.settings['discord_reminder_days'] ?? '3'
  qbittorrentUrl.value = store.settings['qbittorrent_url'] ?? ''
  qbittorrentUsername.value = store.settings['qbittorrent_username'] ?? ''
  // Password is masked, don't pre-fill
  qbittorrentPassword.value = ''
}

async function handleSave(): Promise<void> {
  saving.value = true
  successMessage.value = null
  errorMessage.value = null

  try {
    const updates: Record<string, string | null> = {
      tmdb_api_key: tmdbApiKey.value || null,
      discord_webhook_url: discordWebhookUrl.value || null,
      discord_reminder_days: discordReminderDays.value || '3',
      qbittorrent_url: qbittorrentUrl.value || null,
      qbittorrent_username: qbittorrentUsername.value || null,
    }

    // Only send password if user entered something
    if (qbittorrentPassword.value) {
      updates['qbittorrent_password'] = qbittorrentPassword.value
    }

    const updatedKeys = await store.updateSettings(updates)
    successMessage.value = `${updatedKeys.length} paramètre(s) mis à jour.`
    loadFromStore()
  } catch (err: unknown) {
    const error = err as { response?: { data?: { error?: { message?: string } } } }
    errorMessage.value = error.response?.data?.error?.message || 'Erreur lors de la sauvegarde'
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  try {
    await store.fetchSettings()
    loadFromStore()
  } catch (err: unknown) {
    const error = err as { response?: { data?: { error?: { message?: string } } } }
    errorMessage.value = error.response?.data?.error?.message || 'Erreur lors du chargement des paramètres'
  }
})
</script>

<template>
  <div class="space-y-6 max-w-xl">
    <Message v-if="successMessage" severity="success" :closable="true" @close="successMessage = null">
      {{ successMessage }}
    </Message>
    <Message v-if="errorMessage" severity="error" :closable="true" @close="errorMessage = null">
      {{ errorMessage }}
    </Message>

    <!-- TMDB -->
    <div>
      <h3 class="text-lg font-semibold text-gray-900 mb-3">TMDB</h3>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Clé API TMDB</label>
        <InputText v-model="tmdbApiKey" placeholder="Votre clé API TMDB" class="w-full" />
        <p class="text-xs text-gray-400 mt-1">
          Nécessaire pour l'enrichissement des métadonnées des films.
        </p>
      </div>
    </div>

    <!-- Discord -->
    <div>
      <h3 class="text-lg font-semibold text-gray-900 mb-3">Discord</h3>
      <div class="space-y-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">URL du Webhook</label>
          <InputText v-model="discordWebhookUrl" placeholder="https://discord.com/api/webhooks/..." class="w-full" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Jours de rappel avant suppression</label>
          <InputText v-model="discordReminderDays" placeholder="3" class="w-32" />
        </div>
      </div>
    </div>

    <!-- qBittorrent -->
    <div>
      <h3 class="text-lg font-semibold text-gray-900 mb-3">qBittorrent</h3>
      <div class="space-y-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">URL</label>
          <InputText v-model="qbittorrentUrl" placeholder="http://192.168.1.10:8080" class="w-full" />
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
    </div>

    <!-- Save button -->
    <div class="pt-4 border-t border-gray-200">
      <Button
        label="Sauvegarder"
        icon="pi pi-save"
        @click="handleSave"
        :loading="saving"
      />
    </div>
  </div>
</template>
