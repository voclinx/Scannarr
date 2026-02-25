<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import type { Movie, PaginationMeta } from '@/types'
import type { DataTablePageEvent, DataTableSortEvent } from 'primevue/datatable'

const props = defineProps<{
  movies: Movie[]
  meta: PaginationMeta
  loading: boolean
  sortField: string
  sortOrder: 'ASC' | 'DESC'
}>()

const emit = defineEmits<{
  (e: 'page', page: number): void
  (e: 'sort', field: string, order: 'ASC' | 'DESC'): void
  (e: 'delete', movie: Movie): void
}>()

const router = useRouter()

const primeSort = computed(() => (props.sortOrder === 'ASC' ? 1 : -1))

function formatSize(bytes: number): string {
  if (bytes === 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  const k = 1024
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return (bytes / Math.pow(k, i)).toFixed(i > 1 ? 1 : 0) + ' ' + units[i]
}

function onPage(event: DataTablePageEvent): void {
  emit('page', (event.page ?? 0) + 1)
}

function onSort(event: DataTableSortEvent): void {
  const field = event.sortField as string
  const order = event.sortOrder === 1 ? 'ASC' : 'DESC'

  const fieldMap: Record<string, string> = {
    title: 'title',
    year: 'year',
    rating: 'rating',
    runtime_minutes: 'runtime_minutes',
    created_at: 'created_at',
  }

  emit('sort', fieldMap[field] || field, order)
}

function onRowClick(event: { data: Movie }): void {
  router.push({ name: 'movie-detail', params: { id: event.data.id } })
}

function truncate(text: string, maxLength: number): string {
  if (text.length <= maxLength) return text
  return text.slice(0, maxLength) + '…'
}
</script>

<template>
  <DataTable
    :value="movies"
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
    class="text-sm cursor-pointer"
    selectionMode="single"
    @row-click="onRowClick"
    paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink RowsPerPageDropdown"
  >
    <template #empty>
      <div class="text-center py-8 text-gray-500">
        <i class="pi pi-video text-4xl mb-2 block"></i>
        <p>Aucun film trouvé</p>
      </div>
    </template>

    <!-- Poster + Title -->
    <Column field="title" header="Film" :sortable="true" style="min-width: 350px">
      <template #body="{ data }: { data: Movie }">
        <div class="flex items-center gap-3">
          <img
            v-if="data.poster_url"
            :src="data.poster_url"
            :alt="data.title"
            class="w-10 h-14 object-cover rounded shadow-sm flex-shrink-0"
          />
          <div
            v-else
            class="w-10 h-14 bg-gray-200 rounded flex items-center justify-center flex-shrink-0"
          >
            <i class="pi pi-image text-gray-400"></i>
          </div>
          <div>
            <div class="font-medium text-gray-900">{{ data.title }}</div>
            <div class="text-xs text-gray-400">
              {{ data.year || '—' }}
              <span v-if="data.genres"> · {{ truncate(data.genres, 40) }}</span>
            </div>
          </div>
        </div>
      </template>
    </Column>

    <!-- Year -->
    <Column field="year" header="Année" :sortable="true" style="width: 80px">
      <template #body="{ data }: { data: Movie }">
        <span class="text-gray-600">{{ data.year || '—' }}</span>
      </template>
    </Column>

    <!-- Rating -->
    <Column field="rating" header="Note" :sortable="true" style="width: 80px">
      <template #body="{ data }: { data: Movie }">
        <span v-if="data.rating" class="flex items-center gap-1">
          <i class="pi pi-star-fill text-yellow-400 text-xs"></i>
          {{ data.rating }}
        </span>
        <span v-else class="text-gray-400">—</span>
      </template>
    </Column>

    <!-- File count -->
    <Column header="Fichiers" style="width: 100px">
      <template #body="{ data }: { data: Movie }">
        <Tag
          :value="String(data.file_count)"
          :severity="data.file_count > 0 ? 'info' : 'secondary'"
        />
      </template>
    </Column>

    <!-- Max size -->
    <Column header="Poids max" style="width: 120px">
      <template #body="{ data }: { data: Movie }">
        <span v-if="data.max_file_size_bytes > 0" class="text-gray-600">
          {{ formatSize(data.max_file_size_bytes) }}
        </span>
        <span v-else class="text-gray-400">—</span>
      </template>
    </Column>

    <!-- Radarr status -->
    <Column header="Radarr" style="width: 80px">
      <template #body="{ data }: { data: Movie }">
        <Tag
          v-if="data.is_monitored_radarr"
          value="Suivi"
          severity="success"
        />
        <Tag v-else value="Non suivi" severity="secondary" />
      </template>
    </Column>

    <!-- Actions -->
    <Column header="" style="width: 60px">
      <template #body="{ data }: { data: Movie }">
        <Button
          icon="pi pi-trash"
          severity="danger"
          text
          rounded
          size="small"
          @click.stop="emit('delete', data)"
          v-tooltip.top="'Supprimer'"
        />
      </template>
    </Column>
  </DataTable>
</template>
