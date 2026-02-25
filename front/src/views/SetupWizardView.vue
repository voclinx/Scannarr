<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Button from 'primevue/button'
import Message from 'primevue/message'

const router = useRouter()
const authStore = useAuthStore()

const email = ref('')
const username = ref('')
const password = ref('')
const confirmPassword = ref('')
const error = ref('')
const success = ref(false)

async function handleSetup() {
  error.value = ''

  if (password.value !== confirmPassword.value) {
    error.value = 'Les mots de passe ne correspondent pas'
    return
  }

  if (password.value.length < 8) {
    error.value = 'Le mot de passe doit contenir au moins 8 caractères'
    return
  }

  try {
    await authStore.setup(email.value, username.value, password.value)
    success.value = true
    setTimeout(async () => {
      await router.push({ name: 'login' })
    }, 2000)
  } catch (err: unknown) {
    if (err && typeof err === 'object' && 'response' in err) {
      const axiosErr = err as { response: { status: number; data: { error?: { message?: string } } } }
      if (axiosErr.response.status === 403) {
        error.value = 'Le setup a déjà été effectué. Redirection...'
        setTimeout(async () => {
          await router.push({ name: 'login' })
        }, 2000)
      } else {
        error.value = axiosErr.response.data.error?.message ?? 'Erreur lors du setup'
      }
    } else {
      error.value = 'Impossible de contacter le serveur'
    }
  }
}
</script>

<template>
  <div class="flex items-center justify-center min-h-screen bg-gray-900">
    <div class="w-full max-w-lg p-8 bg-gray-800 rounded-xl shadow-2xl">
      <div class="text-center mb-8">
        <h1 class="text-3xl font-bold text-white">Scanarr</h1>
        <p class="text-gray-400 mt-2">Configuration initiale</p>
        <p class="text-gray-500 text-sm mt-1">Créez le compte administrateur</p>
      </div>

      <Message v-if="success" severity="success" class="mb-4" :closable="false">
        Compte administrateur créé ! Redirection vers la connexion...
      </Message>

      <Message v-if="error" severity="error" class="mb-4" :closable="false">
        {{ error }}
      </Message>

      <form v-if="!success" @submit.prevent="handleSetup" class="space-y-5">
        <div class="flex flex-col gap-2">
          <label for="username" class="text-sm font-medium text-gray-300">Nom d'utilisateur</label>
          <InputText
            id="username"
            v-model="username"
            placeholder="admin"
            class="w-full"
            required
            autofocus
          />
        </div>

        <div class="flex flex-col gap-2">
          <label for="setup-email" class="text-sm font-medium text-gray-300">Email</label>
          <InputText
            id="setup-email"
            v-model="email"
            type="email"
            placeholder="admin@scanarr.local"
            class="w-full"
            required
          />
        </div>

        <div class="flex flex-col gap-2">
          <label for="setup-password" class="text-sm font-medium text-gray-300">Mot de passe</label>
          <Password
            id="setup-password"
            v-model="password"
            placeholder="••••••••"
            class="w-full"
            toggleMask
            inputClass="w-full"
            required
          />
        </div>

        <div class="flex flex-col gap-2">
          <label for="confirm-password" class="text-sm font-medium text-gray-300">Confirmer le mot de passe</label>
          <Password
            id="confirm-password"
            v-model="confirmPassword"
            placeholder="••••••••"
            class="w-full"
            :feedback="false"
            toggleMask
            inputClass="w-full"
            required
          />
        </div>

        <Button
          type="submit"
          label="Créer le compte"
          icon="pi pi-user-plus"
          class="w-full"
          :loading="authStore.loading"
        />
      </form>
    </div>
  </div>
</template>
