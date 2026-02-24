<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'
import Button from 'primevue/button'

const authStore = useAuthStore()

async function handleLogout() {
  await authStore.logout()
}
</script>

<template>
  <header class="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-6 shadow-sm">
    <div>
      <h2 class="text-lg font-semibold text-gray-700">
        <slot name="title" />
      </h2>
    </div>

    <div class="flex items-center gap-4">
      <div v-if="authStore.user" class="flex items-center gap-2 text-sm text-gray-600">
        <i class="pi pi-user"></i>
        <span>{{ authStore.user.username }}</span>
        <span class="text-xs bg-gray-100 px-2 py-0.5 rounded text-gray-500">
          {{ authStore.user.role.replace('ROLE_', '') }}
        </span>
      </div>
      <Button
        icon="pi pi-sign-out"
        severity="secondary"
        text
        rounded
        aria-label="Déconnexion"
        @click="handleLogout"
      />
    </div>
  </header>
</template>
