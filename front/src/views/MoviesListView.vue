<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useMoviesStore } from '@/stores/movies'
import { useAuthStore } from '@/stores/auth'
import MovieTable from '@/components/movies/MovieTable.vue'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import Message from 'primevue/message'
import MultiSelect from 'primevue/multiselect'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import type { Movie } from '@/types'

const moviesStore = useMoviesStore()
const authStore = useAuthStore()
const router = useRouter()

const searchInput = ref('')
const syncLoading = ref(false)
const syncMessage = ref<string | null>(null)
const syncError = ref<string | null>(null)

// Advanced filter state
const seedingStatusSelection = ref<string[]>([])
const inQbitSelection = ref<string | undefined>(undefined)
const inMediaPlayerSelection = ref<string | undefined>(undefined)
const radarrMonitoredSelection = ref<string | undefined>(undefined)
const isProtectedSelection = ref<string | undefined>(undefined)
const hasFilesSelection = ref<string | undefined>(undefined)
const fileCountMin = ref<number | undefined>(undefined)
const fileCountMax = ref<number | undefined>(undefined)

// Filter options
const seedingStatusOptions = [
  { label: 'Orphelin', value: 'orphan' },
  { label: 'Seeding', value: 'seeding' },
  { label: 'Inactif', value: 'inactive' },
  { label: 'Mixte', value: 'mixed' },
]

const booleanOptions = [
  { label: 'Tous', value: undefined },
  { label: 'Oui', value: 'true' },
  { label: 'Non', value: 'false' },
]

const hasActiveFilters = computed(() => {
  return seedingStatusSelection.value.length > 0
    || inQbitSelection.value !== undefined
    || inMediaPlayerSelection.value !== undefined
    || radarrMonitoredSelection.value !== undefined
    || isProtectedSelection.value !== undefined
    || hasFilesSelection.value !== undefined
    || fileCountMin.value !== undefined
    || fileCountMax.value !== undefined
    || searchInput.value !== ''
})

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
  router.push({ name: 'movie-detail', params: { id: movie.id } })
}

function applyFilters(): void {
  moviesStore.setFilters({
    seeding_status: seedingStatusSelection.value.length > 0
      ? seedingStatusSelection.value.join(',')
      : undefined,
    in_qbit: inQbitSelection.value,
    in_media_player: inMediaPlayerSelection.value,
    radarr_monitored: radarrMonitoredSelection.value,
    is_protected: isProtectedSelection.value,
    has_files: hasFilesSelection.value,
    file_count_min: fileCountMin.value !== undefined ? String(fileCountMin.value) : undefined,
    file_count_max: fileCountMax.value !== undefined ? String(fileCountMax.value) : undefined,
  })
}

function onResetFilters(): void {
  searchInput.value = ''
  seedingStatusSelection.value = []
  inQbitSelection.value = undefined
  inMediaPlayerSelection.value = undefined
  radarrMonitoredSelection.value = undefined
  isProtectedSelection.value = undefined
  hasFilesSelection.value = undefined
  fileCountMin.value = undefined
  fileCountMax.value = undefined
  moviesStore.resetFilters()
}

async function onSync(): Promise<void> {
  syncLoading.value = true
  syncMessage.value = null
  syncError.value = null

  try {
    await moviesStore.triggerSync()
    syncMessage.value = 'Synchronisation Radarr lancée en arrière-plan.'
    setTimeout(() => {
      moviesStore.fetchMovies()
    }, 3000)
  } catch (err: unknown) {
    const error = err as { response?: { data?: { error?: { message?: string; code?: number } } }; message?: string }
    const apiMsg = error.response?.data?.error?.message
    const apiCode = error.response?.data?.error?.code
    if (apiMsg) {
      syncError.value = apiCode ? `Erreur ${apiCode} : ${apiMsg}` : apiMsg
    } else {
      syncError.value = error.message || 'Erreur lors de la synchronisation — vérifiez la configuration Radarr dans les paramètres.'
    }
  } finally {
    syncLoading.value = false
  }
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

    <!-- Error messages -->
    <Message v-if="moviesStore.error" severity="error" :closable="true" @close="moviesStore.error = null">
      {{ moviesStore.error }}
    </Message>

    <!-- Sync messages -->
    <Message v-if="syncMessage" severity="success" :closable="true" @close="syncMessage = null">
      {{ syncMessage }}
    </Message>
    <Message v-if="syncError" severity="error" :closable="true" @close="syncError = null">
      {{ syncError }}
    </Message>

    <!-- Filters bar -->
    <div class="bg-white rounded-lg border border-gray-200 p-3 space-y-3">
      <!-- Row 1: Search + Reset -->
      <div class="flex flex-wrap gap-3 items-center">
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

        <Button
          v-if="hasActiveFilters"
          label="Réinitialiser"
          icon="pi pi-times"
          severity="secondary"
          text
          size="small"
          @click="onResetFilters"
        />
      </div>

      <!-- Row 2: Advanced filters -->
      <div class="flex flex-wrap gap-3 items-end">
        <!-- Seeding status -->
        <div class="flex flex-col gap-1">
          <label class="text-xs text-gray-500 font-medium">Statut</label>
          <MultiSelect
            v-model="seedingStatusSelection"
            :options="seedingStatusOptions"
            optionLabel="label"
            optionValue="value"
            placeholder="Tous"
            class="w-40"
            :maxSelectedLabels="2"
            @change="applyFilters"
          />
        </div>

        <!-- In qBit -->
        <div class="flex flex-col gap-1">
          <label class="text-xs text-gray-500 font-medium">Dans qBit</label>
          <Select
            v-model="inQbitSelection"
            :options="booleanOptions"
            optionLabel="label"
            optionValue="value"
            placeholder="Tous"
            class="w-28"
            @change="applyFilters"
          />
        </div>

        <!-- In media player -->
        <div class="flex flex-col gap-1">
          <label class="text-xs text-gray-500 font-medium">Dans lecteur</label>
          <Select
            v-model="inMediaPlayerSelection"
            :options="booleanOptions"
            optionLabel="label"
            optionValue="value"
            placeholder="Tous"
            class="w-28"
            @change="applyFilters"
          />
        </div>

        <!-- Radarr monitored -->
        <div class="flex flex-col gap-1">
          <label class="text-xs text-gray-500 font-medium">Radarr suivi</label>
          <Select
            v-model="radarrMonitoredSelection"
            :options="booleanOptions"
            optionLabel="label"
            optionValue="value"
            placeholder="Tous"
            class="w-28"
            @change="applyFilters"
          />
        </div>

        <!-- Protected -->
        <div class="flex flex-col gap-1">
          <label class="text-xs text-gray-500 font-medium">Protégé</label>
          <Select
            v-model="isProtectedSelection"
            :options="booleanOptions"
            optionLabel="label"
            optionValue="value"
            placeholder="Tous"
            class="w-28"
            @change="applyFilters"
          />
        </div>

        <!-- Has files -->
        <div class="flex flex-col gap-1">
          <label class="text-xs text-gray-500 font-medium">A des fichiers</label>
          <Select
            v-model="hasFilesSelection"
            :options="booleanOptions"
            optionLabel="label"
            optionValue="value"
            placeholder="Tous"
            class="w-28"
            @change="applyFilters"
          />
        </div>

        <!-- File count range -->
        <div class="flex flex-col gap-1">
          <label class="text-xs text-gray-500 font-medium">Nb fichiers</label>
          <div class="flex items-center gap-1">
            <InputNumber
              v-model="fileCountMin"
              placeholder="Min"
              class="w-20"
              :min="0"
              @blur="applyFilters"
            />
            <span class="text-gray-400">-</span>
            <InputNumber
              v-model="fileCountMax"
              placeholder="Max"
              class="w-20"
              :min="0"
              @blur="applyFilters"
            />
          </div>
        </div>
      </div>
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
