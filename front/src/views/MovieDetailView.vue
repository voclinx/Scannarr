<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useMoviesStore } from '@/stores/movies'
import { useDeletionsStore } from '@/stores/deletions'
import { useAuthStore } from '@/stores/auth'
import MovieGlobalDeleteModal from '@/components/movies/MovieGlobalDeleteModal.vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import Message from 'primevue/message'
import ProgressSpinner from 'primevue/progressspinner'
import Dialog from 'primevue/dialog'
import DatePicker from 'primevue/datepicker'
import InputNumber from 'primevue/inputnumber'
import Checkbox from 'primevue/checkbox'
import type { MovieFileDetail } from '@/types'

const route = useRoute()
const router = useRouter()
const moviesStore = useMoviesStore()
const deletionsStore = useDeletionsStore()
const authStore = useAuthStore()

const showDeleteModal = ref(false)
const deleteError = ref<string | null>(null)
const deleteSuccess = ref<string | null>(null)

// Schedule deletion dialog
const showScheduleDialog = ref(false)
const scheduleLoading = ref(false)
const scheduleError = ref<string | null>(null)
const scheduleDate = ref<Date | null>(null)
const scheduleDeletePhysical = ref(true)
const scheduleDeleteRadarr = ref(false)
const scheduleReminderDays = ref(3)

function openScheduleDialog(): void {
  scheduleDate.value = null
  scheduleDeletePhysical.value = true
  scheduleDeleteRadarr.value = false
  scheduleReminderDays.value = 3
  scheduleError.value = null
  showScheduleDialog.value = true
}

async function handleSchedule(): Promise<void> {
  const movie = moviesStore.currentMovie
  if (!movie || !scheduleDate.value) return

  scheduleLoading.value = true
  scheduleError.value = null

  try {
    await deletionsStore.createDeletion({
      scheduled_date: scheduleDate.value.toISOString().split('T')[0] as string,
      delete_physical_files: scheduleDeletePhysical.value,
      delete_radarr_reference: scheduleDeleteRadarr.value,
      delete_media_player_reference: false,
      reminder_days_before: scheduleReminderDays.value,
      items: [
        {
          movie_id: movie.id,
          media_file_ids: movie.files.map((f) => f.id),
        },
      ],
    })

    showScheduleDialog.value = false
    deleteSuccess.value = 'Suppression planifiée créée avec succès'
  } catch (err: unknown) {
    const error = err as { response?: { data?: { error?: { message?: string } } } }
    scheduleError.value =
      error.response?.data?.error?.message || 'Erreur lors de la planification'
  } finally {
    scheduleLoading.value = false
  }
}

function formatSize(bytes: number): string {
  if (bytes === 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  const k = 1024
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return (bytes / Math.pow(k, i)).toFixed(i > 1 ? 1 : 0) + ' ' + units[i]
}

function formatRuntime(minutes: number): string {
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return h > 0 ? `${h}h${m > 0 ? ` ${m}min` : ''}` : `${m}min`
}

function matchedByLabel(matchedBy: string): string {
  const labels: Record<string, string> = {
    radarr_api: 'Radarr API',
    filename_parse: 'Nom de fichier',
    manual: 'Manuel',
  }
  return labels[matchedBy] || matchedBy
}

function confidenceColor(confidence: number): 'success' | 'warn' | 'danger' {
  if (confidence >= 0.9) return 'success'
  if (confidence >= 0.6) return 'warn'
  return 'danger'
}

async function onDeleteConfirm(options: {
  file_ids: string[]
  delete_radarr_reference: boolean
  delete_media_player_reference: boolean
  disable_radarr_auto_search: boolean
}): Promise<void> {
  const movieId = route.params.id as string
  deleteError.value = null
  deleteSuccess.value = null

  try {
    const result = await moviesStore.deleteMovie(movieId, options)
    showDeleteModal.value = false
    deleteSuccess.value = `${result.files_deleted} fichier(s) supprimé(s).`

    if (result.warning) {
      deleteError.value = result.warning
    }

    // Refresh movie detail
    await moviesStore.fetchMovie(movieId)
  } catch (err: unknown) {
    const error = err as { response?: { data?: { error?: { message?: string } } } }
    deleteError.value =
      error.response?.data?.error?.message || 'Erreur lors de la suppression'
  }
}

onMounted(async () => {
  const id = route.params.id as string
  await moviesStore.fetchMovie(id)
})
</script>

<template>
  <div class="space-y-6">
    <!-- Loading -->
    <div v-if="moviesStore.currentMovieLoading" class="flex justify-center py-12">
      <ProgressSpinner />
    </div>

    <!-- Not found -->
    <div
      v-else-if="!moviesStore.currentMovie"
      class="text-center py-12 text-gray-500"
    >
      <i class="pi pi-exclamation-circle text-4xl mb-2 block"></i>
      <p>Film non trouvé</p>
      <Button
        label="Retour à la liste"
        severity="secondary"
        text
        class="mt-4"
        @click="router.push({ name: 'movies' })"
      />
    </div>

    <!-- Movie detail -->
    <template v-else>
      <!-- Back button -->
      <Button
        icon="pi pi-arrow-left"
        label="Retour"
        severity="secondary"
        text
        size="small"
        @click="router.push({ name: 'movies' })"
      />

      <!-- Messages -->
      <Message v-if="deleteSuccess" severity="success" :closable="true" @close="deleteSuccess = null">
        {{ deleteSuccess }}
      </Message>
      <Message v-if="deleteError" severity="warn" :closable="true" @close="deleteError = null">
        {{ deleteError }}
      </Message>

      <!-- Header: poster + info -->
      <div class="flex gap-6 bg-white rounded-lg border border-gray-200 p-6">
        <!-- Poster -->
        <div class="flex-shrink-0">
          <img
            v-if="moviesStore.currentMovie.poster_url"
            :src="moviesStore.currentMovie.poster_url"
            :alt="moviesStore.currentMovie.title"
            class="w-40 h-60 object-cover rounded-lg shadow"
          />
          <div
            v-else
            class="w-40 h-60 bg-gray-200 rounded-lg flex items-center justify-center"
          >
            <i class="pi pi-image text-4xl text-gray-400"></i>
          </div>
        </div>

        <!-- Info -->
        <div class="flex-1 space-y-3">
          <div class="flex items-start justify-between">
            <div>
              <h1 class="text-2xl font-bold text-gray-900">
                {{ moviesStore.currentMovie.title }}
              </h1>
              <p
                v-if="moviesStore.currentMovie.original_title && moviesStore.currentMovie.original_title !== moviesStore.currentMovie.title"
                class="text-sm text-gray-400"
              >
                {{ moviesStore.currentMovie.original_title }}
              </p>
            </div>
            <div class="flex gap-2" v-if="authStore.hasMinRole('ROLE_ADVANCED_USER') && moviesStore.currentMovie.files.length > 0">
              <Button
                label="Planifier"
                icon="pi pi-calendar"
                severity="warn"
                size="small"
                @click="openScheduleDialog"
              />
              <Button
                label="Supprimer"
                icon="pi pi-trash"
                severity="danger"
                size="small"
                @click="showDeleteModal = true"
              />
            </div>
          </div>

          <!-- Metadata chips -->
          <div class="flex flex-wrap gap-2">
            <Tag
              v-if="moviesStore.currentMovie.year"
              :value="String(moviesStore.currentMovie.year)"
              severity="secondary"
            />
            <Tag
              v-if="moviesStore.currentMovie.rating"
              :value="`★ ${moviesStore.currentMovie.rating}`"
              severity="warn"
            />
            <Tag
              v-if="moviesStore.currentMovie.runtime_minutes"
              :value="formatRuntime(moviesStore.currentMovie.runtime_minutes)"
              severity="secondary"
            />
            <Tag
              v-if="moviesStore.currentMovie.radarr_instance"
              :value="moviesStore.currentMovie.radarr_instance.name"
              severity="info"
            />
            <Tag
              v-if="moviesStore.currentMovie.radarr_monitored"
              value="Suivi Radarr"
              severity="success"
            />
          </div>

          <!-- Genres -->
          <p v-if="moviesStore.currentMovie.genres" class="text-sm text-gray-600">
            {{ moviesStore.currentMovie.genres }}
          </p>

          <!-- Synopsis -->
          <p
            v-if="moviesStore.currentMovie.synopsis"
            class="text-sm text-gray-700 leading-relaxed"
          >
            {{ moviesStore.currentMovie.synopsis }}
          </p>
        </div>
      </div>

      <!-- Linked files -->
      <div class="bg-white rounded-lg border border-gray-200 p-4">
        <h2 class="text-lg font-semibold text-gray-900 mb-3">
          Fichiers liés ({{ moviesStore.currentMovie.files.length }})
        </h2>

        <DataTable
          :value="moviesStore.currentMovie.files"
          dataKey="id"
          stripedRows
          class="text-sm"
        >
          <template #empty>
            <div class="text-center py-4 text-gray-500">
              Aucun fichier lié à ce film
            </div>
          </template>

          <Column field="file_name" header="Fichier" style="min-width: 300px">
            <template #body="{ data }: { data: MovieFileDetail }">
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

          <Column field="volume_name" header="Volume" style="width: 130px">
            <template #body="{ data }: { data: MovieFileDetail }">
              <Tag :value="data.volume_name" severity="info" />
            </template>
          </Column>

          <Column header="Taille" style="width: 100px">
            <template #body="{ data }: { data: MovieFileDetail }">
              {{ formatSize(data.file_size_bytes) }}
            </template>
          </Column>

          <Column header="Résolution" style="width: 100px">
            <template #body="{ data }: { data: MovieFileDetail }">
              <Tag v-if="data.resolution" :value="data.resolution" severity="secondary" />
              <span v-else class="text-gray-400">—</span>
            </template>
          </Column>

          <Column header="Hardlinks" style="width: 90px">
            <template #body="{ data }: { data: MovieFileDetail }">
              <Tag
                :value="String(data.hardlink_count)"
                :severity="data.hardlink_count > 1 ? 'warn' : 'secondary'"
              />
            </template>
          </Column>

          <Column header="Liaison" style="width: 130px">
            <template #body="{ data }: { data: MovieFileDetail }">
              <div class="flex flex-col gap-1">
                <Tag
                  :value="matchedByLabel(data.matched_by)"
                  :severity="confidenceColor(data.confidence)"
                  class="text-xs"
                />
                <span class="text-xs text-gray-400">
                  Confiance : {{ Math.round(data.confidence * 100) }}%
                </span>
              </div>
            </template>
          </Column>
        </DataTable>
      </div>

      <!-- Delete modal -->
      <MovieGlobalDeleteModal
        v-model:visible="showDeleteModal"
        :movie="moviesStore.currentMovie"
        @confirm="onDeleteConfirm"
      />

      <!-- Schedule deletion dialog -->
      <Dialog
        v-model:visible="showScheduleDialog"
        :modal="true"
        header="Planifier la suppression"
        :style="{ width: '450px' }"
      >
        <div class="space-y-4">
          <Message v-if="scheduleError" severity="error" :closable="false">{{ scheduleError }}</Message>

          <Message severity="info" :closable="false">
            <strong>{{ moviesStore.currentMovie.title }}</strong>
            — {{ moviesStore.currentMovie.files.length }} fichier(s) seront inclus.
          </Message>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Date de suppression</label>
            <DatePicker
              v-model="scheduleDate"
              :minDate="new Date()"
              dateFormat="dd/mm/yy"
              placeholder="Sélectionner une date"
              class="w-full"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Rappel (jours avant)</label>
            <InputNumber v-model="scheduleReminderDays" :min="0" :max="30" class="w-full" />
          </div>

          <div class="space-y-2 pt-2 border-t border-gray-200">
            <div class="flex items-center gap-2">
              <Checkbox v-model="scheduleDeletePhysical" :binary="true" inputId="schedDeletePhysical" />
              <label for="schedDeletePhysical" class="cursor-pointer text-sm">Supprimer les fichiers physiques</label>
            </div>
            <div class="flex items-center gap-2">
              <Checkbox v-model="scheduleDeleteRadarr" :binary="true" inputId="schedDeleteRadarr" />
              <label for="schedDeleteRadarr" class="cursor-pointer text-sm">Supprimer la référence Radarr</label>
            </div>
          </div>
        </div>

        <template #footer>
          <div class="flex justify-end gap-2">
            <Button label="Annuler" severity="secondary" text @click="showScheduleDialog = false" />
            <Button
              label="Planifier"
              icon="pi pi-calendar"
              @click="handleSchedule"
              :loading="scheduleLoading"
              :disabled="!scheduleDate"
            />
          </div>
        </template>
      </Dialog>
    </template>
  </div>
</template>
