<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import ProgressBar from 'primevue/progressbar'
import Message from 'primevue/message'
import ProgressSpinner from 'primevue/progressspinner'
import Dialog from 'primevue/dialog'
import Checkbox from 'primevue/checkbox'
import DatePicker from 'primevue/datepicker'
import { useSuggestionsStore } from '@/stores/suggestions'
import { usePresetsStore } from '@/stores/presets'
import { useVolumesStore } from '@/stores/volumes'
import { useScore } from '@/composables/useScore'
import { useFormatters } from '@/composables/useFormatters'
import { useApi } from '@/composables/useApi'
import type { SuggestionItem, DeletionPreset } from '@/types'

interface ScoredItem extends SuggestionItem {
  _score: number
  _best_ratio: number | null
  _seed_time_max: number
  _seeding_status: 'seeding' | 'orphan' | 'mixed' | undefined
  _is_protected: boolean
}

const router = useRouter()
const suggestionsStore = useSuggestionsStore()
const presetsStore = usePresetsStore()
const volumesStore = useVolumesStore()
const { calculateItemScore, scoreColor } = useScore()
const { formatSize, formatSeedTime, formatRatio, ratioSeverity, seedingStatusLabel, seedingStatusSeverity } =
  useFormatters()
const api = useApi()

const selectedPresetId = ref<string | null>(null)
const selectedSeedingStatus = ref<'all' | 'orphans_only' | 'seeding_only'>('all')
const selectedVolumeId = ref<string | null>(null)
const spaceGoalGb = ref<number>(500)
const selectedItems = ref<ScoredItem[]>([])
const showBatchDeleteDialog = ref(false)
const showBatchScheduleDialog = ref(false)
const batchDeleteRadarr = ref(false)
const batchDisableAutoSearch = ref(false)
const batchScheduleDate = ref<Date | null>(null)
const batchLoading = ref(false)
const batchError = ref<string | null>(null)
const batchSuccess = ref<string | null>(null)
const syncing = ref(false)

const selectedPreset = computed((): DeletionPreset | null => {
  if (selectedPresetId.value) {
    return presetsStore.presets.find((p) => p.id === selectedPresetId.value) ?? null
  }
  return presetsStore.defaultPreset
})

const scoredItems = computed((): ScoredItem[] => {
  const preset = selectedPreset.value
  if (!preset) return []

  return suggestionsStore.suggestions
    .map((item) => {
      const allTorrents = item.files.flatMap((f) => f.torrents)
      const ratios = allTorrents.map((t) => t.ratio)
      const seedTimes = allTorrents.map((t) => t.seed_time_seconds)

      const _best_ratio = ratios.length > 0 ? Math.max(...ratios) : null
      const _seed_time_max = seedTimes.length > 0 ? Math.max(...seedTimes) : 0

      let _seeding_status: 'seeding' | 'orphan' | 'mixed' | undefined
      if (allTorrents.length === 0) {
        _seeding_status = 'orphan'
      } else {
        const hasSeeding = allTorrents.some((t) => t.status === 'seeding')
        const hasNotSeeding = allTorrents.some((t) => t.status !== 'seeding')
        if (hasSeeding && hasNotSeeding) _seeding_status = 'mixed'
        else if (hasSeeding) _seeding_status = 'seeding'
        else _seeding_status = 'orphan'
      }

      const _is_protected = item.files.some((f) => f.is_protected)

      return {
        ...item,
        _score: calculateItemScore(item, preset),
        _best_ratio,
        _seed_time_max,
        _seeding_status,
        _is_protected,
      }
    })
    .sort((a, b) => b._score - a._score)
})

const selectedFreedBytes = computed(() =>
  selectedItems.value.reduce((acc, item) => acc + item.total_freed_bytes, 0),
)

const spaceGoalBytes = computed(() => spaceGoalGb.value * 1_073_741_824)

const spaceGoalPercent = computed(() => {
  if (spaceGoalBytes.value === 0) return 0
  return Math.min(100, Math.round((selectedFreedBytes.value / spaceGoalBytes.value) * 100))
})

const presetOptions = computed(() =>
  presetsStore.presets.map((p) => ({ label: p.name, value: p.id })),
)

const seedingStatusOptions = [
  { label: 'Tous', value: 'all' },
  { label: 'Orphelins', value: 'orphans_only' },
  { label: 'En seed', value: 'seeding_only' },
]

const volumeOptions = computed(() => [
  { label: 'Tous les volumes', value: null },
  ...volumesStore.volumes.map((v) => ({ label: v.name, value: v.id })),
])

watch([selectedSeedingStatus, selectedVolumeId], () => {
  selectedItems.value = []
  suggestionsStore.fetchSuggestions({
    seeding_status: selectedSeedingStatus.value,
    volume_id: selectedVolumeId.value ?? undefined,
    preset_id: selectedPresetId.value ?? undefined,
  })
})

function rowClass(data: ScoredItem): string {
  if (data._is_protected || data.blocked_by_tracker_rules) return 'opacity-50'
  return ''
}

function isSelected(item: ScoredItem): boolean {
  return selectedItems.value.some((i) => i.movie.id === item.movie.id)
}

function toggleSelection(item: ScoredItem, checked: boolean): void {
  if (checked) {
    if (!selectedItems.value.find((i) => i.movie.id === item.movie.id)) {
      selectedItems.value.push(item)
    }
  } else {
    selectedItems.value = selectedItems.value.filter((i) => i.movie.id !== item.movie.id)
  }
}

async function syncQBittorrent(): Promise<void> {
  syncing.value = true
  try {
    await api.post('/qbittorrent/sync')
    await suggestionsStore.fetchSuggestions()
  } finally {
    syncing.value = false
  }
}

async function handleBatchDelete(): Promise<void> {
  batchLoading.value = true
  batchError.value = null
  try {
    const items = selectedItems.value.map((item) => ({
      movie_id: item.movie.id,
      file_ids: item.files.map((f) => f.media_file_id),
    }))
    await suggestionsStore.batchDelete(items, {
      delete_radarr_reference: batchDeleteRadarr.value,
      disable_radarr_auto_search: batchDisableAutoSearch.value,
    })
    showBatchDeleteDialog.value = false
    selectedItems.value = []
    batchSuccess.value = 'Suppression déclenchée avec succès'
    await suggestionsStore.fetchSuggestions()
  } catch (err: unknown) {
    const error = err as { response?: { data?: { error?: { message?: string } } } }
    batchError.value = error.response?.data?.error?.message || 'Erreur lors de la suppression'
  } finally {
    batchLoading.value = false
  }
}

async function handleBatchSchedule(): Promise<void> {
  if (!batchScheduleDate.value) return
  batchLoading.value = true
  batchError.value = null
  try {
    const items = selectedItems.value.map((item) => ({
      movie_id: item.movie.id,
      file_ids: item.files.map((f) => f.media_file_id),
    }))
    const dateStr = batchScheduleDate.value.toISOString().substring(0, 10)
    await suggestionsStore.batchSchedule(items, dateStr, {
      delete_radarr_reference: batchDeleteRadarr.value,
      disable_radarr_auto_search: batchDisableAutoSearch.value,
    })
    showBatchScheduleDialog.value = false
    selectedItems.value = []
    batchSuccess.value = 'Suppression planifiée avec succès'
    await suggestionsStore.fetchSuggestions()
  } catch (err: unknown) {
    const error = err as { response?: { data?: { error?: { message?: string } } } }
    batchError.value = error.response?.data?.error?.message || 'Erreur lors de la planification'
  } finally {
    batchLoading.value = false
  }
}

onMounted(async () => {
  await Promise.all([presetsStore.fetchPresets(), volumesStore.fetchVolumes(), suggestionsStore.fetchSuggestions()])
  if (presetsStore.defaultPreset) {
    selectedPresetId.value = presetsStore.defaultPreset.id
  }
})
</script>

<template>
  <div class="space-y-4">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Suggestions de suppression</h1>
        <p class="text-sm text-gray-500 mt-1">
          {{ suggestionsStore.meta.total }} suggestion(s) — score calculé côté client
        </p>
      </div>
      <Button
        label="Sync qBittorrent"
        icon="pi pi-refresh"
        severity="secondary"
        outlined
        :loading="syncing"
        @click="syncQBittorrent"
      />
    </div>

    <!-- Messages -->
    <Message v-if="batchSuccess" severity="success" :closable="true" @close="batchSuccess = null">
      {{ batchSuccess }}
    </Message>
    <Message v-if="batchError" severity="error" :closable="true" @close="batchError = null">
      {{ batchError }}
    </Message>

    <!-- Filtres -->
    <div class="flex flex-wrap gap-3 items-center bg-white rounded-lg border border-gray-200 p-3">
      <div class="flex items-center gap-2">
        <label class="text-sm font-medium text-gray-600">Preset</label>
        <Select
          v-model="selectedPresetId"
          :options="presetOptions"
          optionLabel="label"
          optionValue="value"
          placeholder="Preset par défaut"
          class="w-48"
        />
      </div>
      <div class="flex items-center gap-2">
        <label class="text-sm font-medium text-gray-600">Statut</label>
        <Select
          v-model="selectedSeedingStatus"
          :options="seedingStatusOptions"
          optionLabel="label"
          optionValue="value"
          class="w-40"
        />
      </div>
      <div class="flex items-center gap-2">
        <label class="text-sm font-medium text-gray-600">Volume</label>
        <Select
          v-model="selectedVolumeId"
          :options="volumeOptions"
          optionLabel="label"
          optionValue="value"
          class="w-48"
        />
      </div>
    </div>

    <!-- Barre objectif espace -->
    <div class="bg-white rounded-lg border border-gray-200 p-4 space-y-3">
      <div class="flex items-center gap-4 flex-wrap">
        <div class="flex items-center gap-2 shrink-0 overflow-hidden">
          <label class="text-sm font-medium text-gray-600 shrink-0">Objectif</label>
          <InputNumber
            v-model="spaceGoalGb"
            :min="1"
            :max="100000"
            suffix=" Go"
            class="w-36"
            inputClass="text-right w-full"
          />
        </div>
        <div class="text-sm text-gray-600 whitespace-nowrap">
          Sélectionné :
          <span class="font-semibold text-gray-900">{{ formatSize(selectedFreedBytes) }}</span>
          /
          <span class="font-semibold">{{ formatSize(spaceGoalBytes) }}</span>
          <span class="ml-2 text-xs">({{ spaceGoalPercent }}%)</span>
        </div>
        <div class="text-sm text-gray-500 whitespace-nowrap">{{ selectedItems.length }} film(s) sélectionné(s)</div>
      </div>
      <ProgressBar :value="spaceGoalPercent" class="h-2" />
    </div>

    <!-- DataTable -->
    <div class="bg-white rounded-lg border border-gray-200">
      <DataTable
        :value="scoredItems"
        :loading="suggestionsStore.loading"
        :row-class="rowClass"
        dataKey="movie.id"
        stripedRows
        class="text-sm"
      >
        <template #empty>
          <div v-if="suggestionsStore.loading" class="flex justify-center py-8">
            <ProgressSpinner style="width: 40px; height: 40px" />
          </div>
          <div v-else class="text-center py-8 text-gray-400">
            Aucune suggestion — lancez une synchronisation qBittorrent ou modifiez les filtres.
          </div>
        </template>

        <!-- Checkbox -->
        <Column style="width: 3rem" :sortable="false">
          <template #body="{ data }">
            <span v-if="data._is_protected" title="Fichier protégé">
              <i class="pi pi-lock text-gray-400"></i>
            </span>
            <span v-else-if="data.blocked_by_tracker_rules" :title="data.blocked_reason ?? 'Bloqué par règle tracker'">
              <i class="pi pi-shield text-orange-400"></i>
            </span>
            <Checkbox
              v-else
              :model-value="isSelected(data)"
              :binary="true"
              @update:model-value="(val: boolean) => toggleSelection(data, val)"
            />
          </template>
        </Column>

        <!-- Score -->
        <Column field="_score" header="Score" sortable style="width: 5rem">
          <template #body="{ data }">
            <Tag :value="String(data._score)" :severity="scoreColor(data._score)" />
          </template>
        </Column>

        <!-- Film -->
        <Column field="movie.title" header="Film" sortable>
          <template #body="{ data }">
            <div class="flex items-center gap-2">
              <a
                class="text-blue-600 hover:underline cursor-pointer font-medium"
                @click="router.push({ name: 'movie-detail', params: { id: data.movie.id } })"
              >
                {{ data.movie.title }}
                <span v-if="data.movie.year" class="text-gray-400 text-xs ml-1">({{ data.movie.year }})</span>
              </a>
              <Tag v-if="data.multi_file" value="Multi" severity="secondary" class="text-xs" />
            </div>
          </template>
        </Column>

        <!-- Ratio -->
        <Column header="Ratio" style="width: 6rem">
          <template #body="{ data }">
            <Tag
              v-if="data._best_ratio !== null"
              :value="formatRatio(data._best_ratio)"
              :severity="ratioSeverity(data._best_ratio)"
            />
            <span v-else class="text-gray-400">—</span>
          </template>
        </Column>

        <!-- Seed -->
        <Column header="Seed max" style="width: 7rem">
          <template #body="{ data }">
            <span v-if="data._seed_time_max > 0">{{ formatSeedTime(data._seed_time_max) }}</span>
            <span v-else class="text-gray-400">—</span>
          </template>
        </Column>

        <!-- Taille -->
        <Column field="total_size_bytes" header="Taille" sortable style="width: 7rem">
          <template #body="{ data }">
            {{ formatSize(data.total_size_bytes) }}
          </template>
        </Column>

        <!-- Libéré -->
        <Column field="total_freed_bytes" header="Libéré" sortable style="width: 7rem">
          <template #body="{ data }">
            <span class="text-green-600 font-medium">{{ formatSize(data.total_freed_bytes) }}</span>
          </template>
        </Column>

        <!-- Statut -->
        <Column header="Statut" style="width: 8rem">
          <template #body="{ data }">
            <Tag
              v-if="data._seeding_status"
              :value="seedingStatusLabel(data._seeding_status)"
              :severity="seedingStatusSeverity(data._seeding_status)"
            />
            <span v-else class="text-gray-400">—</span>
          </template>
        </Column>
      </DataTable>
    </div>

    <!-- Actions batch -->
    <div
      v-if="selectedItems.length > 0"
      class="flex items-center gap-3 bg-blue-50 border border-blue-200 rounded-lg p-3"
    >
      <span class="text-sm font-medium text-blue-800">{{ selectedItems.length }} film(s) sélectionné(s)</span>
      <Button
        label="Supprimer"
        icon="pi pi-trash"
        severity="danger"
        size="small"
        @click="showBatchDeleteDialog = true"
      />
      <Button
        label="Planifier"
        icon="pi pi-calendar"
        severity="warning"
        size="small"
        @click="showBatchScheduleDialog = true"
      />
      <Button
        label="Désélectionner tout"
        icon="pi pi-times"
        severity="secondary"
        size="small"
        outlined
        @click="selectedItems = []"
      />
    </div>

    <!-- Dialog suppression batch -->
    <Dialog
      v-model:visible="showBatchDeleteDialog"
      header="Suppression immédiate"
      :modal="true"
      :closable="!batchLoading"
      style="width: 450px"
    >
      <div class="space-y-4">
        <p class="text-sm text-gray-600">
          Supprimer immédiatement les fichiers de
          <strong>{{ selectedItems.length }} film(s)</strong> ?
          <br />
          Espace libéré : <strong>{{ formatSize(selectedFreedBytes) }}</strong>
        </p>
        <div class="space-y-2">
          <div class="flex items-center gap-2">
            <Checkbox v-model="batchDeleteRadarr" :binary="true" inputId="del-radarr" />
            <label for="del-radarr" class="text-sm cursor-pointer">Supprimer dans Radarr</label>
          </div>
          <div class="flex items-center gap-2">
            <Checkbox v-model="batchDisableAutoSearch" :binary="true" inputId="del-autosearch" />
            <label for="del-autosearch" class="text-sm cursor-pointer">Désactiver la recherche automatique Radarr</label>
          </div>
        </div>
        <Message v-if="batchError" severity="error">{{ batchError }}</Message>
      </div>
      <template #footer>
        <Button label="Annuler" severity="secondary" outlined @click="showBatchDeleteDialog = false" :disabled="batchLoading" />
        <Button label="Supprimer" severity="danger" icon="pi pi-trash" @click="handleBatchDelete" :loading="batchLoading" />
      </template>
    </Dialog>

    <!-- Dialog planification batch -->
    <Dialog
      v-model:visible="showBatchScheduleDialog"
      header="Planifier la suppression"
      :modal="true"
      :closable="!batchLoading"
      style="width: 450px"
    >
      <div class="space-y-4">
        <p class="text-sm text-gray-600">
          Planifier la suppression de <strong>{{ selectedItems.length }} film(s)</strong>.
          <br />
          Espace libéré estimé : <strong>{{ formatSize(selectedFreedBytes) }}</strong>
        </p>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Date de suppression</label>
          <DatePicker v-model="batchScheduleDate" dateFormat="dd/mm/yy" :minDate="new Date()" class="w-full" />
        </div>
        <div class="space-y-2">
          <div class="flex items-center gap-2">
            <Checkbox v-model="batchDeleteRadarr" :binary="true" inputId="sched-radarr" />
            <label for="sched-radarr" class="text-sm cursor-pointer">Supprimer dans Radarr</label>
          </div>
          <div class="flex items-center gap-2">
            <Checkbox v-model="batchDisableAutoSearch" :binary="true" inputId="sched-autosearch" />
            <label for="sched-autosearch" class="text-sm cursor-pointer">Désactiver la recherche automatique Radarr</label>
          </div>
        </div>
        <Message v-if="batchError" severity="error">{{ batchError }}</Message>
      </div>
      <template #footer>
        <Button label="Annuler" severity="secondary" outlined @click="showBatchScheduleDialog = false" :disabled="batchLoading" />
        <Button
          label="Planifier"
          icon="pi pi-calendar"
          @click="handleBatchSchedule"
          :loading="batchLoading"
          :disabled="!batchScheduleDate"
        />
      </template>
    </Dialog>
  </div>
</template>
