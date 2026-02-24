<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Button from 'primevue/button'
import Message from 'primevue/message'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

const email = ref('')
const password = ref('')
const error = ref('')

async function handleLogin() {
  error.value = ''
  try {
    await authStore.login(email.value, password.value)
    const redirect = route.query.redirect as string | undefined
    await router.push(redirect ?? { name: 'dashboard' })
  } catch (err: unknown) {
    if (err && typeof err === 'object' && 'response' in err) {
      const axiosErr = err as { response: { data: { error?: { message?: string } } } }
      error.value = axiosErr.response.data.error?.message ?? 'Erreur de connexion'
    } else {
      error.value = 'Impossible de contacter le serveur'
    }
  }
}
</script>

<template>
  <div class="flex items-center justify-center min-h-screen bg-gray-900">
    <div class="w-full max-w-md p-8 bg-gray-800 rounded-xl shadow-2xl">
      <div class="text-center mb-8">
        <h1 class="text-3xl font-bold text-white">Scanarr</h1>
        <p class="text-gray-400 mt-2">Connexion</p>
      </div>

      <Message v-if="error" severity="error" class="mb-4" :closable="false">
        {{ error }}
      </Message>

      <form @submit.prevent="handleLogin" class="space-y-5">
        <div class="flex flex-col gap-2">
          <label for="email" class="text-sm font-medium text-gray-300">Email</label>
          <InputText
            id="email"
            v-model="email"
            type="email"
            placeholder="admin@scanarr.local"
            class="w-full"
            required
            autofocus
          />
        </div>

        <div class="flex flex-col gap-2">
          <label for="password" class="text-sm font-medium text-gray-300">Mot de passe</label>
          <Password
            id="password"
            v-model="password"
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
          label="Se connecter"
          icon="pi pi-sign-in"
          class="w-full"
          :loading="authStore.loading"
        />
      </form>
    </div>
  </div>
</template>
