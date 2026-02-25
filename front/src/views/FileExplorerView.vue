<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useFilesStore } from '@/stores/files'
import { useVolumesStore } from '@/stores/volumes'
import FileTable from '@/components/files/FileTable.vue'
import FileDeleteModal from '@/components/files/FileDeleteModal.vue'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Message from 'primevue/message'
import type { MediaFile } from '@/types'

const filesStore = useFilesStore()
const volumesStore = useVolumesStore()
const searchInput = ref('')
const selectedVolumeId = ref<string | undefined>(undefined)
const showDeleteModal = ref(false)
const selectedFile = ref<MediaFile | null>(null)
const deleteError = ref<string | null>(null)

// Debounced search
let searchTimeout: ReturnType<typeof setTimeout> | null = null

function onSearchInput(): void {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    filesStore.setSearch(searchInput.value)
  }, 400)
}

function onVolumeChange(): void {
  filesStore.setVolumeFilter(selectedVolumeId.value)
}

function onPage(page: number): void {
  filesStore.setPage(page)
}

function onSort(field: string, order: 'ASC' | 'DESC'): void {
  filesStore.setSort(field, order)
}

function onDeleteClick(file: MediaFile): void {
  selectedFile.value = file
  deleteError.value = null
  showDeleteModal.value = true
}

async function onDeleteConfirm(options: {
  delete_physical: boolean
  delete_radarr_reference: boolean
}): Promise<void> {
  if (!selectedFile.value) return

  try {
    await filesStore.deleteFile(selectedFile.value.id, options)
    showDeleteModal.value = false
    selectedFile.value = null
    deleteError.value = null
  } catch (err: unknown) {
    const error = err as { response?: { data?: { error?: { message?: string } } } }
    deleteError.value = error.response?.data?.error?.message || 'Erreur lors de la suppression'
  }
}

function onResetFilters(): void {
  searchInput.value = ''
  selectedVolumeId.value = undefined
  filesStore.resetFilters()
}

function formatSize(bytes: number): string {
  if (bytes === 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  const k = 1024
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return (bytes / Math.pow(k, i)).toFixed(i > 1 ? 1 : 0) + ' ' + units[i]
}

// Volume options for the dropdown
const volumeOptions = ref<Array<{ label: string; value: string | undefined }>>([])

watch(
  () => volumesStore.volumes,
  (vols) => {
    volumeOptions.value = [
      { label: 'Tous les volumes', value: undefined },
      ...vols.map((v) => ({ label: v.name, value: v.id })),
    ]
  },
  { immediate: true },
)

onMounted(async () => {
  await Promise.all([volumesStore.fetchVolumes(), filesStore.fetchFiles()])
})
</script>

<template>
  <div class="space-y-4">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Explorateur de fichiers</h1>
        <p class="text-sm text-gray-500 mt-1">
          {{ filesStore.meta.total }} fichier(s)
          <span v-if="filesStore.totalSize > 0"> &mdash; {{ formatSize(filesStore.totalSize) }} affich&eacute;(s)</span>
        </p>
      </div>
    </div>

    <!-- Filters bar -->
    <div class="flex flex-wrap gap-3 items-center bg-white rounded-lg border border-gray-200 p-3">
      <!-- Search -->
      <div class="flex-1 min-w-[200px]">
        <span class="p-input-icon-left w-full">
          <i class="pi pi-search" />
          <InputText
            v-model="searchInput"
            placeholder="Rechercher un fichier..."
            class="w-full"
            @input="onSearchInput"
          />
        </span>
      </div>

      <!-- Volume filter -->
      <Select
        v-model="selectedVolumeId"
        :options="volumeOptions"
        optionLabel="label"
        optionValue="value"
        placeholder="Volume"
        class="w-48"
        @change="onVolumeChange"
      />

      <!-- Reset -->
      <Button
        icon="pi pi-filter-slash"
        severity="secondary"
        text
        rounded
        v-tooltip.top="'R\u00e9initialiser les filtres'"
        @click="onResetFilters"
      />
    </div>

    <!-- Delete error -->
    <Message v-if="deleteError" severity="error" :closable="true" @close="deleteError = null">
      {{ deleteError }}
    </Message>

    <!-- File table -->
    <FileTable
      :files="filesStore.files"
      :meta="filesStore.meta"
      :loading="filesStore.loading"
      :sortField="filesStore.filters.sort || 'detected_at'"
      :sortOrder="filesStore.filters.order || 'DESC'"
      @page="onPage"
      @sort="onSort"
      @delete="onDeleteClick"
    />

    <!-- Delete modal -->
    <FileDeleteModal
      v-model:visible="showDeleteModal"
      :file="selectedFile"
      @confirm="onDeleteConfirm"
    />
  </div>
</template>
