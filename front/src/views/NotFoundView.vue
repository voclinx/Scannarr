<script setup lang="ts">
import { useRouter } from 'vue-router'
import Button from 'primevue/button'

const router = useRouter()
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50">
    <div class="text-center">
      <h1 class="text-6xl font-bold text-gray-300 mb-4">404</h1>
      <p class="text-xl text-gray-600 mb-6">Page introuvable</p>
      <Button
        label="Retour au tableau de bord"
        icon="pi pi-home"
        @click="router.push({ name: 'dashboard' })"
      />
    </div>
  </div>
</template>
