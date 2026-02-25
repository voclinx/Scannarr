<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useSettingsStore } from '@/stores/settings'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Message from 'primevue/message'

const store = useSettingsStore()

const webhookUrl = ref('')
const reminderDays = ref(3)
const saving = ref(false)
const saveSuccess = ref(false)
const saveError = ref<string | null>(null)
const testLoading = ref(false)
const testResult = ref<{ success: boolean; message: string } | null>(null)

async function handleSave(): Promise<void> {
  saving.value = true
  saveError.value = null
  saveSuccess.value = false

  try {
    await store.updateSettings({
      discord_webhook_url: webhookUrl.value || null,
      discord_reminder_days: String(reminderDays.value),
    })
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

async function handleTest(): Promise<void> {
  if (!webhookUrl.value) {
    testResult.value = { success: false, message: 'URL du webhook requise' }
    return
  }

  testLoading.value = true
  testResult.value = null

  try {
    // Send a test message directly
    const response = await fetch(webhookUrl.value, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        embeds: [
          {
            title: '🔔 Test Scanarr',
            description: 'Les notifications Discord fonctionnent correctement !',
            color: 3066993,
            footer: { text: 'Scanarr — Test de notification' },
            timestamp: new Date().toISOString(),
          },
        ],
      }),
    })

    if (response.ok) {
      testResult.value = { success: true, message: 'Notification envoyée avec succès !' }
    } else {
      testResult.value = {
        success: false,
        message: `Erreur HTTP ${response.status}`,
      }
    }
  } catch {
    testResult.value = { success: false, message: 'Erreur de connexion au webhook' }
  } finally {
    testLoading.value = false
  }
}

const loadError = ref<string | null>(null)

onMounted(async () => {
  try {
    await store.fetchSettings()
    webhookUrl.value = store.settings.discord_webhook_url || ''
    reminderDays.value = parseInt(store.settings.discord_reminder_days || '3', 10)
  } catch (err: unknown) {
    const error = err as { response?: { data?: { error?: { message?: string } } } }
    loadError.value = error.response?.data?.error?.message || 'Erreur lors du chargement des paramètres Discord'
  }
})
</script>

<template>
  <div class="space-y-4">
    <h3 class="text-lg font-semibold text-gray-900">Notifications Discord</h3>

    <Message v-if="loadError" severity="error" :closable="true" @close="loadError = null">
      {{ loadError }}
    </Message>
    <Message v-if="saveSuccess" severity="success" :closable="false">
      Configuration sauvegardée avec succès
    </Message>
    <Message v-if="saveError" severity="error" :closable="false">{{ saveError }}</Message>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">URL du Webhook Discord</label>
      <div class="flex gap-2">
        <InputText
          v-model="webhookUrl"
          placeholder="https://discord.com/api/webhooks/..."
          class="flex-1"
        />
        <Button
          label="Tester"
          icon="pi pi-bolt"
          severity="secondary"
          :loading="testLoading"
          @click="handleTest"
        />
      </div>
      <p class="text-xs text-gray-400 mt-1">
        Créez un webhook dans les paramètres de votre serveur Discord → Intégrations → Webhooks
      </p>

      <div v-if="testResult" class="mt-2">
        <Message
          :severity="testResult.success ? 'success' : 'error'"
          :closable="false"
          class="text-sm"
        >
          {{ testResult.message }}
        </Message>
      </div>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">
        Rappel avant suppression (jours)
      </label>
      <InputNumber v-model="reminderDays" :min="0" :max="30" class="w-full" />
      <p class="text-xs text-gray-400 mt-1">
        Nombre de jours avant la date planifiée pour envoyer un rappel Discord. 0 = pas de rappel.
      </p>
    </div>

    <div class="pt-4">
      <Button label="Sauvegarder" icon="pi pi-save" @click="handleSave" :loading="saving" />
    </div>
  </div>
</template>
