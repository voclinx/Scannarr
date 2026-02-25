<script setup lang="ts">
import { computed } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import type { MediaFile, PaginationMeta } from '@/types'
import type { DataTablePageEvent, DataTableSortEvent } from 'primevue/datatable'

const props = defineProps<{
  files: MediaFile[]
  meta: PaginationMeta
  loading: boolean
  sortField: string
  sortOrder: 'ASC' | 'DESC'
}>()

const emit = defineEmits<{
  (e: 'page', page: number): void
  (e: 'sort', field: string, order: 'ASC' | 'DESC'): void
  (e: 'delete', file: MediaFile): void
}>()

const primeSort = computed(() => (props.sortOrder === 'ASC' ? 1 : -1))

function formatSize(bytes: number): string {
  if (bytes === 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  const k = 1024
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return (bytes / Math.pow(k, i)).toFixed(i > 1 ? 1 : 0) + ' ' + units[i]
}

function formatDate(dateStr: string): string {
  const d = new Date(dateStr)
  return d.toLocaleDateString('fr-FR', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function onPage(event: DataTablePageEvent): void {
  emit('page', (event.page ?? 0) + 1) // PrimeVue uses 0-based pages
}

function onSort(event: DataTableSortEvent): void {
  const field = event.sortField as string
  const order = event.sortOrder === 1 ? 'ASC' : 'DESC'

  // Map PrimeVue field names to API sort fields
  const fieldMap: Record<string, string> = {
    file_name: 'file_name',
    file_size_bytes: 'file_size_bytes',
    hardlink_count: 'hardlink_count',
    detected_at: 'detected_at',
  }

  emit('sort', fieldMap[field] || field, order)
}
</script>

<template>
  <DataTable
    :value="files"
    :loading="loading"
    :lazy="true"
    :paginator="true"
    :rows="meta.limit"
    :totalRecords="meta.total"
    :first="(meta.page - 1) * meta.limit"
    :sortField="sortField"
    :sortOrder="primeSort"
    :rowsPerPageOptions="[10, 25, 50, 100]"
    dataKey="id"
    stripedRows
    removableSort
    @page="onPage"
    @sort="onSort"
    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink RowsPerPageDropdown"
    class="text-sm"
  >
    <template #empty>
      <div class="text-center py-8 text-gray-500">
        <i class="pi pi-folder-open text-4xl mb-2 block"></i>
        <p>Aucun fichier trouvé</p>
      </div>
    </template>

    <Column field="file_name" header="Nom du fichier" :sortable="true" style="min-width: 300px">
      <template #body="{ data }: { data: MediaFile }">
        <div>
          <div class="font-medium text-gray-900 truncate max-w-[400px]" :title="data.file_name">
            {{ data.file_name }}
          </div>
          <div class="text-xs text-gray-400 truncate max-w-[400px]" :title="data.file_path">
            {{ data.file_path }}
          </div>
        </div>
      </template>
    </Column>

    <Column field="volume_name" header="Volume" style="min-width: 120px">
      <template #body="{ data }: { data: MediaFile }">
        <Tag :value="data.volume_name" severity="info" />
      </template>
    </Column>

    <Column field="file_size_bytes" header="Taille" :sortable="true" style="min-width: 100px">
      <template #body="{ data }: { data: MediaFile }">
        {{ formatSize(data.file_size_bytes) }}
      </template>
    </Column>

    <Column field="hardlink_count" header="Hardlinks" :sortable="true" style="min-width: 100px">
      <template #body="{ data }: { data: MediaFile }">
        <Tag
          :value="String(data.hardlink_count)"
          :severity="data.hardlink_count > 1 ? 'warn' : 'secondary'"
        />
      </template>
    </Column>

    <Column header="Liens" style="min-width: 120px">
      <template #body="{ data }: { data: MediaFile }">
        <div class="flex gap-1">
          <Tag v-if="data.is_linked_radarr" value="Radarr" severity="success" />
          <Tag v-if="data.is_linked_media_player" value="Player" severity="info" />
          <span v-if="!data.is_linked_radarr && !data.is_linked_media_player" class="text-gray-400 text-xs">—</span>
        </div>
      </template>
    </Column>

    <Column field="detected_at" header="Détecté le" :sortable="true" style="min-width: 150px">
      <template #body="{ data }: { data: MediaFile }">
        <span class="text-xs text-gray-600">{{ formatDate(data.detected_at) }}</span>
      </template>
    </Column>

    <Column header="" style="width: 60px">
      <template #body="{ data }: { data: MediaFile }">
        <Button
          icon="pi pi-trash"
          severity="danger"
          text
          rounded
          size="small"
          @click="emit('delete', data)"
          v-tooltip.top="'Supprimer'"
        />
      </template>
    </Column>
  </DataTable>
</template>
