<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useMoviesStore } from '@/stores/movies'
import { useAuthStore } from '@/stores/auth'
import MovieTable from '@/components/movies/MovieTable.vue'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import Message from 'primevue/message'
import type { Movie } from '@/types'

const moviesStore = useMoviesStore()
const authStore = useAuthStore()
const router = useRouter()

const searchInput = ref('')
const syncLoading = ref(false)
const syncMessage = ref<string | null>(null)
const syncError = ref<string | null>(null)

// Debounced search
let searchTimeout: ReturnType<typeof setTimeout> | null = null

function onSearchInput(): void {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    moviesStore.setSearch(searchInput.value)
  }, 400)
}

function onPage(page: number): void {
  moviesStore.setPage(page)
}

function onSort(field: string, order: 'ASC' | 'DESC'): void {
  moviesStore.setSort(field, order)
}

function onDeleteClick(movie: Movie): void {
  // Navigate to detail view where global delete modal is available
  router.push({ name: 'movie-detail', params: { id: movie.id } })
}

async function onSync(): Promise<void> {
  syncLoading.value = true
  syncMessage.value = null
  syncError.value = null

  try {
    await moviesStore.triggerSync()
    syncMessage.value = 'Synchronisation Radarr lancée en arrière-plan.'
    // Refresh list after a short delay
    setTimeout(() => {
      moviesStore.fetchMovies()
    }, 3000)
  } catch (err: unknown) {
    const error = err as { response?: { data?: { error?: { message?: string } } } }
    syncError.value = error.response?.data?.error?.message || 'Erreur lors de la synchronisation'
  } finally {
    syncLoading.value = false
  }
}

function onResetFilters(): void {
  searchInput.value = ''
  moviesStore.resetFilters()
}

onMounted(async () => {
  await moviesStore.fetchMovies()
})
</script>

<template>
  <div class="space-y-4">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Films</h1>
        <p class="text-sm text-gray-500 mt-1">
          {{ moviesStore.meta.total }} film(s)
        </p>
      </div>
      <div v-if="authStore.hasMinRole('ROLE_ADMIN')">
        <Button
          label="Sync Radarr"
          icon="pi pi-sync"
          :loading="syncLoading"
          @click="onSync"
          severity="info"
          size="small"
        />
      </div>
    </div>

    <!-- Sync messages -->
    <Message v-if="syncMessage" severity="success" :closable="true" @close="syncMessage = null">
      {{ syncMessage }}
    </Message>
    <Message v-if="syncError" severity="error" :closable="true" @close="syncError = null">
      {{ syncError }}
    </Message>

    <!-- Filters bar -->
    <div class="flex flex-wrap gap-3 items-center bg-white rounded-lg border border-gray-200 p-3">
      <!-- Search -->
      <div class="flex-1 min-w-[200px]">
        <span class="p-input-icon-left w-full">
          <i class="pi pi-search" />
          <InputText
            v-model="searchInput"
            placeholder="Rechercher un film..."
            class="w-full"
            @input="onSearchInput"
          />
        </span>
      </div>

      <!-- Reset -->
      <Button
        icon="pi pi-filter-slash"
        severity="secondary"
        text
        rounded
        v-tooltip.top="'Réinitialiser les filtres'"
        @click="onResetFilters"
      />
    </div>

    <!-- Movie table -->
    <MovieTable
      :movies="moviesStore.movies"
      :meta="moviesStore.meta"
      :loading="moviesStore.loading"
      :sortField="moviesStore.filters.sort || 'title'"
      :sortOrder="moviesStore.filters.order || 'ASC'"
      @page="onPage"
      @sort="onSort"
      @delete="onDeleteClick"
    />
  </div>
</template>
