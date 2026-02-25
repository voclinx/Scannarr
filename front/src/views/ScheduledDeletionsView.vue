<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useDeletionsStore } from '@/stores/deletions'
import { useAuthStore } from '@/stores/auth'
import { useMoviesStore } from '@/stores/movies'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import DatePicker from 'primevue/datepicker'
import InputNumber from 'primevue/inputnumber'
import Checkbox from 'primevue/checkbox'
import Select from 'primevue/select'
import Message from 'primevue/message'
import type { ScheduledDeletion, Movie, CreateScheduledDeletionPayload } from '@/types'

const deletionsStore = useDeletionsStore()
const authStore = useAuthStore()
const moviesStore = useMoviesStore()

const statusFilter = ref<string | undefined>(undefined)
const statusOptions = [
  { label: 'Tous', value: undefined },
  { label: 'En attente', value: 'pending' },
  { label: 'Rappel envoyé', value: 'reminder_sent' },
  { label: 'En cours', value: 'executing' },
  { label: 'Terminée', value: 'completed' },
  { label: 'Échouée', value: 'failed' },
  { label: 'Annulée', value: 'cancelled' },
]

// Create dialog
const showCreateDialog = ref(false)
const createLoading = ref(false)
const createError = ref<string | null>(null)
const formDate = ref<Date | null>(null)
const formDeletePhysical = ref(true)
const formDeleteRadarr = ref(false)
const formDeleteMediaPlayer = ref(false)
const formReminderDays = ref(3)
const formSelectedMovies = ref<Array<{ movie: Movie; fileIds: string[] }>>([])

// Detail dialog
const showDetailDialog = ref(false)

function statusSeverity(
  status: ScheduledDeletion['status'],
): 'success' | 'info' | 'warn' | 'danger' | 'secondary' | 'contrast' {
  const map: Record<string, 'success' | 'info' | 'warn' | 'danger' | 'secondary' | 'contrast'> = {
    pending: 'info',
    reminder_sent: 'warn',
    executing: 'contrast',
    completed: 'success',
    failed: 'danger',
    cancelled: 'secondary',
  }
  return map[status] || 'secondary'
}

function statusLabel(status: ScheduledDeletion['status']): string {
  const map: Record<string, string> = {
    pending: 'En attente',
    reminder_sent: 'Rappel envoyé',
    executing: 'En cours',
    completed: 'Terminée',
    failed: 'Échouée',
    cancelled: 'Annulée',
  }
  return map[status] || status
}

function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
}

const canCancel = computed(() => {
  return (d: ScheduledDeletion) => {
    if (!['pending', 'reminder_sent'].includes(d.status)) return false
    return authStore.hasMinRole('ROLE_ADVANCED_USER')
  }
})

function openCreateDialog(): void {
  formDate.value = null
  formDeletePhysical.value = true
  formDeleteRadarr.value = false
  formDeleteMediaPlayer.value = false
  formReminderDays.value = 3
  formSelectedMovies.value = []
  createError.value = null
  showCreateDialog.value = true

  // Load movies for selection
  if (moviesStore.movies.length === 0) {
    moviesStore.fetchMovies({ limit: 100 })
  }
}

function addMovieToDeletion(movie: Movie): void {
  if (formSelectedMovies.value.some((m) => m.movie.id === movie.id)) return
  formSelectedMovies.value.push({
    movie,
    fileIds: movie.files_summary.map((f) => f.id),
  })
}

function removeMovieFromDeletion(movieId: string): void {
  formSelectedMovies.value = formSelectedMovies.value.filter((m) => m.movie.id !== movieId)
}

function onMovieSelected(event: { value: Movie }): void {
  if (event.value) {
    addMovieToDeletion(event.value)
  }
}

async function handleCreate(): Promise<void> {
  if (!formDate.value || formSelectedMovies.value.length === 0) {
    createError.value = 'Sélectionnez une date et au moins un film'
    return
  }

  createLoading.value = true
  createError.value = null

  try {
    const payload: CreateScheduledDeletionPayload = {
      scheduled_date: formDate.value.toISOString().split('T')[0] as string,
      delete_physical_files: formDeletePhysical.value,
      delete_radarr_reference: formDeleteRadarr.value,
      delete_media_player_reference: formDeleteMediaPlayer.value,
      reminder_days_before: formReminderDays.value,
      items: formSelectedMovies.value.map((m) => ({
        movie_id: m.movie.id,
        media_file_ids: m.fileIds,
      })),
    }

    await deletionsStore.createDeletion(payload)
    showCreateDialog.value = false
  } catch (err: unknown) {
    const error = err as { response?: { data?: { error?: { message?: string } } } }
    createError.value =
      error.response?.data?.error?.message || 'Erreur lors de la création'
  } finally {
    createLoading.value = false
  }
}

async function handleCancel(deletion: ScheduledDeletion): Promise<void> {
  if (!confirm(`Annuler la suppression planifiée du ${formatDate(deletion.scheduled_date)} ?`))
    return
  await deletionsStore.cancelDeletion(deletion.id)
}

async function openDetail(deletion: ScheduledDeletion): Promise<void> {
  await deletionsStore.fetchDeletion(deletion.id)
  showDetailDialog.value = true
}

function onStatusFilterChange(): void {
  deletionsStore.fetchDeletions({ status: statusFilter.value })
}

// Available movies (those with files)
const availableMovies = computed(() => {
  return moviesStore.movies.filter((m) => m.file_count > 0)
})

onMounted(() => {
  deletionsStore.fetchDeletions()
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-gray-900">Suppressions planifiées</h1>
      <div class="flex items-center gap-3">
        <Select
          v-model="statusFilter"
          :options="statusOptions"
          optionLabel="label"
          optionValue="value"
          placeholder="Filtrer par statut"
          class="w-48"
          @change="onStatusFilterChange"
        />
        <Button
          v-if="authStore.hasMinRole('ROLE_ADVANCED_USER')"
          label="Planifier"
          icon="pi pi-plus"
          @click="openCreateDialog"
        />
      </div>
    </div>

    <DataTable
      :value="deletionsStore.deletions"
      :loading="deletionsStore.loading"
      dataKey="id"
      stripedRows
      class="text-sm"
    >
      <template #empty>
        <div class="text-center py-8 text-gray-500">
          <i class="pi pi-calendar text-4xl mb-2 block"></i>
          <p>Aucune suppression planifiée</p>
        </div>
      </template>

      <Column header="Date" style="width: 120px">
        <template #body="{ data }: { data: ScheduledDeletion }">
          <span class="font-medium">{{ formatDate(data.scheduled_date) }}</span>
          <div class="text-xs text-gray-400">{{ data.execution_time }}</div>
        </template>
      </Column>

      <Column header="Statut" style="width: 130px">
        <template #body="{ data }: { data: ScheduledDeletion }">
          <Tag :value="statusLabel(data.status)" :severity="statusSeverity(data.status)" />
        </template>
      </Column>

      <Column header="Films" style="width: 80px">
        <template #body="{ data }: { data: ScheduledDeletion }">
          <Tag :value="String(data.items_count)" severity="info" />
        </template>
      </Column>

      <Column header="Fichiers" style="width: 80px">
        <template #body="{ data }: { data: ScheduledDeletion }">
          <span class="text-gray-600">{{ data.total_files_count }}</span>
        </template>
      </Column>

      <Column header="Options" style="min-width: 200px">
        <template #body="{ data }: { data: ScheduledDeletion }">
          <div class="flex flex-wrap gap-1">
            <Tag
              v-if="data.delete_physical_files"
              value="Fichiers"
              severity="danger"
              class="text-xs"
            />
            <Tag
              v-if="data.delete_radarr_reference"
              value="Radarr"
              severity="warn"
              class="text-xs"
            />
            <Tag
              v-if="data.delete_media_player_reference"
              value="Lecteur"
              severity="info"
              class="text-xs"
            />
          </div>
        </template>
      </Column>

      <Column header="Créé par" style="width: 120px">
        <template #body="{ data }: { data: ScheduledDeletion }">
          <span class="text-gray-600">{{ data.created_by }}</span>
        </template>
      </Column>

      <Column header="" style="width: 120px">
        <template #body="{ data }: { data: ScheduledDeletion }">
          <div class="flex gap-1">
            <Button
              icon="pi pi-eye"
              size="small"
              text
              rounded
              @click="openDetail(data)"
            />
            <Button
              v-if="canCancel(data)"
              icon="pi pi-times"
              size="small"
              text
              rounded
              severity="danger"
              @click="handleCancel(data)"
            />
          </div>
        </template>
      </Column>
    </DataTable>

    <!-- Create Dialog -->
    <Dialog
      v-model:visible="showCreateDialog"
      :modal="true"
      header="Planifier une suppression"
      :style="{ width: '650px' }"
    >
      <div class="space-y-4">
        <Message v-if="createError" severity="error" :closable="false">{{ createError }}</Message>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Date de suppression</label>
          <DatePicker
            v-model="formDate"
            :minDate="new Date()"
            dateFormat="dd/mm/yy"
            placeholder="Sélectionner une date"
            class="w-full"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Rappel (jours avant)</label>
          <InputNumber v-model="formReminderDays" :min="0" :max="30" class="w-full" />
        </div>

        <div class="space-y-2 pt-2 border-t border-gray-200">
          <div class="flex items-center gap-2">
            <Checkbox v-model="formDeletePhysical" :binary="true" inputId="formDeletePhysical" />
            <label for="formDeletePhysical" class="cursor-pointer text-sm"
              >Supprimer les fichiers physiques</label
            >
          </div>
          <div class="flex items-center gap-2">
            <Checkbox v-model="formDeleteRadarr" :binary="true" inputId="formDeleteRadarr" />
            <label for="formDeleteRadarr" class="cursor-pointer text-sm"
              >Supprimer la référence Radarr</label
            >
          </div>
          <div class="flex items-center gap-2">
            <Checkbox
              v-model="formDeleteMediaPlayer"
              :binary="true"
              inputId="formDeleteMediaPlayer"
            />
            <label for="formDeleteMediaPlayer" class="cursor-pointer text-sm"
              >Supprimer la référence lecteur multimédia</label
            >
          </div>
        </div>

        <!-- Movie selection -->
        <div class="pt-2 border-t border-gray-200">
          <label class="block text-sm font-medium text-gray-700 mb-2">Films à supprimer</label>

          <!-- Selected movies -->
          <div v-if="formSelectedMovies.length > 0" class="space-y-2 mb-3">
            <div
              v-for="item in formSelectedMovies"
              :key="item.movie.id"
              class="flex items-center gap-3 p-2 bg-red-50 border border-red-100 rounded"
            >
              <img
                v-if="item.movie.poster_url"
                :src="item.movie.poster_url"
                :alt="item.movie.title"
                class="w-8 h-12 object-cover rounded"
              />
              <div class="flex-1 min-w-0">
                <div class="text-sm font-medium text-gray-900 truncate">
                  {{ item.movie.title }}
                  <span v-if="item.movie.year" class="text-gray-400">({{ item.movie.year }})</span>
                </div>
                <div class="text-xs text-gray-500">
                  {{ item.fileIds.length }} fichier(s)
                </div>
              </div>
              <Button
                icon="pi pi-times"
                size="small"
                text
                rounded
                severity="danger"
                @click="removeMovieFromDeletion(item.movie.id)"
              />
            </div>
          </div>

          <!-- Movie picker -->
          <Select
            :options="availableMovies"
            optionLabel="title"
            placeholder="Rechercher un film..."
            filter
            filterPlaceholder="Rechercher..."
            class="w-full"
            @change="onMovieSelected"
          >
            <template #option="slotProps">
              <div class="flex items-center gap-2">
                <span>{{ (slotProps.option as Movie).title }}</span>
                <span v-if="(slotProps.option as Movie).year" class="text-gray-400">({{ (slotProps.option as Movie).year }})</span>
                <Tag :value="`${(slotProps.option as Movie).file_count} fichier(s)`" severity="info" class="text-xs" />
              </div>
            </template>
          </Select>
        </div>
      </div>

      <template #footer>
        <div class="flex justify-end gap-2">
          <Button
            label="Annuler"
            severity="secondary"
            text
            @click="showCreateDialog = false"
          />
          <Button
            label="Planifier"
            icon="pi pi-calendar"
            @click="handleCreate"
            :loading="createLoading"
            :disabled="!formDate || formSelectedMovies.length === 0"
          />
        </div>
      </template>
    </Dialog>

    <!-- Detail Dialog -->
    <Dialog
      v-model:visible="showDetailDialog"
      :modal="true"
      header="Détail de la suppression planifiée"
      :style="{ width: '650px' }"
    >
      <div v-if="deletionsStore.currentDeletion" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <span class="text-sm text-gray-500">Date</span>
            <p class="font-medium">
              {{ formatDate(deletionsStore.currentDeletion.scheduled_date) }}
              à {{ deletionsStore.currentDeletion.execution_time }}
            </p>
          </div>
          <div>
            <span class="text-sm text-gray-500">Statut</span>
            <p>
              <Tag
                :value="statusLabel(deletionsStore.currentDeletion.status)"
                :severity="statusSeverity(deletionsStore.currentDeletion.status)"
              />
            </p>
          </div>
          <div>
            <span class="text-sm text-gray-500">Créé par</span>
            <p class="font-medium">{{ deletionsStore.currentDeletion.created_by }}</p>
          </div>
          <div>
            <span class="text-sm text-gray-500">Rappel</span>
            <p class="font-medium">{{ deletionsStore.currentDeletion.reminder_days_before }} jours avant</p>
          </div>
        </div>

        <div class="flex flex-wrap gap-2">
          <Tag
            v-if="deletionsStore.currentDeletion.delete_physical_files"
            value="Suppression fichiers"
            severity="danger"
          />
          <Tag
            v-if="deletionsStore.currentDeletion.delete_radarr_reference"
            value="Déréférencement Radarr"
            severity="warn"
          />
          <Tag
            v-if="deletionsStore.currentDeletion.delete_media_player_reference"
            value="Déréférencement lecteur"
            severity="info"
          />
        </div>

        <!-- Items -->
        <div class="border-t border-gray-200 pt-3">
          <h3 class="text-sm font-medium text-gray-700 mb-2">
            Films ({{ deletionsStore.currentDeletion.items.length }})
          </h3>
          <div class="space-y-2 max-h-60 overflow-y-auto">
            <div
              v-for="item in deletionsStore.currentDeletion.items"
              :key="item.id"
              class="flex items-center gap-3 p-2 border border-gray-100 rounded"
            >
              <img
                v-if="item.movie?.poster_url"
                :src="item.movie.poster_url"
                :alt="item.movie?.title"
                class="w-8 h-12 object-cover rounded"
              />
              <div class="flex-1 min-w-0">
                <div class="text-sm font-medium text-gray-900">
                  {{ item.movie?.title || 'Film inconnu' }}
                  <span v-if="item.movie?.year" class="text-gray-400"
                    >({{ item.movie.year }})</span
                  >
                </div>
                <div class="text-xs text-gray-500">
                  {{ item.media_file_ids.length }} fichier(s)
                </div>
                <div v-if="item.error_message" class="text-xs text-red-500 mt-1">
                  {{ item.error_message }}
                </div>
              </div>
              <Tag
                :value="item.status === 'pending' ? 'En attente' : item.status === 'deleted' ? 'Supprimé' : item.status === 'failed' ? 'Échoué' : item.status"
                :severity="item.status === 'deleted' ? 'success' : item.status === 'failed' ? 'danger' : 'secondary'"
              />
            </div>
          </div>
        </div>
      </div>

      <template #footer>
        <Button label="Fermer" severity="secondary" @click="showDetailDialog = false" />
      </template>
    </Dialog>
  </div>
</template>
