<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import Message from 'primevue/message'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Checkbox from 'primevue/checkbox'
import { usePresetsStore } from '@/stores/presets'
import { useSuggestionsStore } from '@/stores/suggestions'
import { useScore } from '@/composables/useScore'
import { useFormatters } from '@/composables/useFormatters'
import type { DeletionPreset, PresetCriteria } from '@/types'

interface PreviewItem {
  movie: { id: string | null; title: string; year?: number }
  _score: number
  total_freed_bytes: number
  files: Array<{ torrents: Array<{ ratio: number; seed_time_seconds: number }>; file_size_bytes: number }>
}

const presetsStore = usePresetsStore()
const suggestionsStore = useSuggestionsStore()
const { calculateItemScore, scoreColor } = useScore()
const { formatSize, formatSeedTime, formatRatio } = useFormatters()

const selectedPresetId = ref<string | null>(null)
const editedCriteria = ref<PresetCriteria | null>(null)
const presetName = ref<string>('')
const saving = ref(false)
const saveError = ref<string | null>(null)
const showNewDialog = ref(false)
const newPresetName = ref('')

const presetOptions = computed(() =>
  presetsStore.presets.map((p) => ({ label: `${p.name}${p.is_system ? ' [système]' : ''}${p.is_default ? ' ★' : ''}`, value: p.id })),
)

const selectedPreset = computed((): DeletionPreset | null =>
  presetsStore.presets.find((p) => p.id === selectedPresetId.value) ?? null,
)

const isSystemPreset = computed(() => selectedPreset.value?.is_system ?? false)

const previewItems = computed((): PreviewItem[] => {
  if (!editedCriteria.value || !selectedPreset.value) return []
  const previewPreset: DeletionPreset = {
    ...selectedPreset.value,
    criteria: editedCriteria.value,
  }
  return suggestionsStore.suggestions
    .slice(0, 20)
    .map((item) => ({
      ...item,
      _score: calculateItemScore(item, previewPreset),
    }))
    .sort((a, b) => b._score - a._score)
})

const previewStats = computed(() => {
  const count = previewItems.value.length
  const avgScore =
    count > 0 ? Math.round(previewItems.value.reduce((acc, i) => acc + i._score, 0) / count) : 0
  const highScoreCount = previewItems.value.filter((i) => i._score > 50).length
  return { count, avgScore, highScoreCount }
})

watch(selectedPresetId, () => {
  const preset = selectedPreset.value
  if (preset) {
    editedCriteria.value = JSON.parse(JSON.stringify(preset.criteria))
    presetName.value = preset.name
  } else {
    editedCriteria.value = null
    presetName.value = ''
  }
})

async function handleSave(): Promise<void> {
  if (!selectedPreset.value || !editedCriteria.value) return
  saving.value = true
  saveError.value = null
  try {
    await presetsStore.updatePreset(selectedPreset.value.id, {
      name: presetName.value,
      criteria: editedCriteria.value,
    })
  } catch (err: unknown) {
    const error = err as { response?: { data?: { error?: { message?: string } } } }
    saveError.value = error.response?.data?.error?.message || 'Erreur lors de la sauvegarde'
  } finally {
    saving.value = false
  }
}

async function handleCreate(): Promise<void> {
  if (!newPresetName.value.trim()) return
  saveError.value = null
  try {
    const preset = await presetsStore.createPreset({
      name: newPresetName.value.trim(),
      is_default: false,
      criteria: {
        ratio: { enabled: true, threshold: 1.0, weight: 30, operator: 'lt' },
        seed_time: { enabled: true, threshold_days: 7, weight: 20, operator: 'gt' },
        file_size: { enabled: false, threshold_gb: 10, weight: 20, operator: 'gt' },
        orphan_qbit: { enabled: true, weight: 50 },
        cross_seed: { enabled: false, weight: 10, per_tracker: false },
      },
      filters: {
        seeding_status: 'all',
        exclude_protected: true,
        min_score: 0,
        max_results: null,
      },
    })
    showNewDialog.value = false
    newPresetName.value = ''
    selectedPresetId.value = preset.id
  } catch (err: unknown) {
    const error = err as { response?: { data?: { error?: { message?: string } } } }
    saveError.value = error.response?.data?.error?.message || 'Erreur lors de la création'
  }
}

async function handleDuplicate(): Promise<void> {
  if (!selectedPreset.value) return
  saveError.value = null
  try {
    const source = selectedPreset.value
    const preset = await presetsStore.createPreset({
      name: `${source.name} (copie)`,
      is_default: false,
      criteria: JSON.parse(JSON.stringify(source.criteria)),
      filters: JSON.parse(JSON.stringify(source.filters)),
    })
    selectedPresetId.value = preset.id
  } catch (err: unknown) {
    const error = err as { response?: { data?: { error?: { message?: string } } } }
    saveError.value = error.response?.data?.error?.message || 'Erreur lors de la duplication'
  }
}

async function handleDelete(): Promise<void> {
  if (!selectedPreset.value || isSystemPreset.value) return
  saveError.value = null
  try {
    await presetsStore.deletePreset(selectedPreset.value.id)
    selectedPresetId.value = presetsStore.defaultPreset?.id ?? null
  } catch (err: unknown) {
    const error = err as { response?: { data?: { error?: { message?: string } } } }
    saveError.value = error.response?.data?.error?.message || 'Erreur lors de la suppression'
  }
}

onMounted(async () => {
  await presetsStore.fetchPresets()
  if (suggestionsStore.suggestions.length === 0) {
    await suggestionsStore.fetchSuggestions()
  }
  if (presetsStore.defaultPreset) {
    selectedPresetId.value = presetsStore.defaultPreset.id
  }
})
</script>

<template>
  <div class="space-y-6">
    <h3 class="text-lg font-semibold text-gray-900">Presets de suppression</h3>

    <!-- Sélection preset + actions -->
    <div class="flex flex-wrap items-center gap-2">
      <Select
        v-model="selectedPresetId"
        :options="presetOptions"
        optionLabel="label"
        optionValue="value"
        placeholder="Sélectionner un preset"
        class="w-64"
      />
      <Button
        label="Nouveau"
        icon="pi pi-plus"
        severity="secondary"
        size="small"
        @click="showNewDialog = true"
      />
      <Button
        label="Dupliquer"
        icon="pi pi-copy"
        severity="secondary"
        size="small"
        outlined
        :disabled="!selectedPreset"
        @click="handleDuplicate"
      />
      <Button
        label="Sauvegarder"
        icon="pi pi-save"
        size="small"
        :loading="saving"
        :disabled="isSystemPreset || !selectedPreset"
        @click="handleSave"
      />
      <Button
        label="Supprimer"
        icon="pi pi-trash"
        severity="danger"
        size="small"
        outlined
        :disabled="isSystemPreset || !selectedPreset"
        @click="handleDelete"
      />
    </div>

    <!-- Dialog nouveau preset -->
    <Dialog v-model:visible="showNewDialog" header="Nouveau preset" :modal="true" style="width: 360px">
      <div class="space-y-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Nom</label>
          <InputText v-model="newPresetName" placeholder="Mon preset" class="w-full" autofocus />
        </div>
      </div>
      <template #footer>
        <Button label="Annuler" severity="secondary" outlined @click="showNewDialog = false" />
        <Button label="Créer" icon="pi pi-check" @click="handleCreate" :disabled="!newPresetName.trim()" />
      </template>
    </Dialog>

    <Message v-if="saveError" severity="error" :closable="true" @close="saveError = null">
      {{ saveError }}
    </Message>

    <!-- Formulaire critères -->
    <div v-if="selectedPreset && editedCriteria" class="space-y-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Nom du preset</label>
        <InputText v-model="presetName" :disabled="isSystemPreset" class="w-64" />
      </div>

      <Message v-if="isSystemPreset" severity="info" :closable="false">
        Ce preset est un preset système — il ne peut pas être modifié. Dupliquez-le pour le personnaliser.
      </Message>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-gray-200">
              <th class="text-left py-2 pr-4 font-medium text-gray-600 w-6">Actif</th>
              <th class="text-left py-2 pr-4 font-medium text-gray-600">Critère</th>
              <th class="text-left py-2 pr-4 font-medium text-gray-600">Seuil</th>
              <th class="text-left py-2 font-medium text-gray-600">Poids</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <!-- Ratio -->
            <tr>
              <td class="py-3 pr-4">
                <Checkbox
                  v-model="editedCriteria.ratio.enabled"
                  :binary="true"
                  :disabled="isSystemPreset"
                />
              </td>
              <td class="py-3 pr-4 font-medium">Ratio</td>
              <td class="py-3 pr-4">
                <InputNumber
                  v-model="editedCriteria.ratio.threshold"
                  :min="0"
                  :max="100"
                  :minFractionDigits="1"
                  :maxFractionDigits="2"
                  :disabled="isSystemPreset || !editedCriteria.ratio.enabled"
                  class="w-28"
                  placeholder="ex: 1.0"
                />
              </td>
              <td class="py-3">
                <InputNumber
                  v-model="editedCriteria.ratio.weight"
                  :min="0"
                  :max="100"
                  :disabled="isSystemPreset || !editedCriteria.ratio.enabled"
                  class="w-24"
                  suffix=" pts"
                />
              </td>
            </tr>
            <!-- Seed time -->
            <tr>
              <td class="py-3 pr-4">
                <Checkbox
                  v-model="editedCriteria.seed_time.enabled"
                  :binary="true"
                  :disabled="isSystemPreset"
                />
              </td>
              <td class="py-3 pr-4 font-medium">Seed time</td>
              <td class="py-3 pr-4">
                <InputNumber
                  v-model="editedCriteria.seed_time.threshold_days"
                  :min="0"
                  :max="3650"
                  :disabled="isSystemPreset || !editedCriteria.seed_time.enabled"
                  class="w-28"
                  suffix=" j"
                />
              </td>
              <td class="py-3">
                <InputNumber
                  v-model="editedCriteria.seed_time.weight"
                  :min="0"
                  :max="100"
                  :disabled="isSystemPreset || !editedCriteria.seed_time.enabled"
                  class="w-24"
                  suffix=" pts"
                />
              </td>
            </tr>
            <!-- Taille fichier -->
            <tr>
              <td class="py-3 pr-4">
                <Checkbox
                  v-model="editedCriteria.file_size.enabled"
                  :binary="true"
                  :disabled="isSystemPreset"
                />
              </td>
              <td class="py-3 pr-4 font-medium">Taille fichier</td>
              <td class="py-3 pr-4">
                <InputNumber
                  v-model="editedCriteria.file_size.threshold_gb"
                  :min="0"
                  :max="100000"
                  :minFractionDigits="1"
                  :disabled="isSystemPreset || !editedCriteria.file_size.enabled"
                  class="w-28"
                  suffix=" Go"
                />
              </td>
              <td class="py-3">
                <InputNumber
                  v-model="editedCriteria.file_size.weight"
                  :min="0"
                  :max="100"
                  :disabled="isSystemPreset || !editedCriteria.file_size.enabled"
                  class="w-24"
                  suffix=" pts"
                />
              </td>
            </tr>
            <!-- Orphelin qBit -->
            <tr>
              <td class="py-3 pr-4">
                <Checkbox
                  v-model="editedCriteria.orphan_qbit.enabled"
                  :binary="true"
                  :disabled="isSystemPreset"
                />
              </td>
              <td class="py-3 pr-4 font-medium">Orphelin qBit</td>
              <td class="py-3 pr-4 text-gray-400 text-xs italic">— (booléen)</td>
              <td class="py-3">
                <InputNumber
                  v-model="editedCriteria.orphan_qbit.weight"
                  :min="0"
                  :max="100"
                  :disabled="isSystemPreset || !editedCriteria.orphan_qbit.enabled"
                  class="w-24"
                  suffix=" pts"
                />
              </td>
            </tr>
            <!-- Cross-seed -->
            <tr>
              <td class="py-3 pr-4">
                <Checkbox
                  v-model="editedCriteria.cross_seed.enabled"
                  :binary="true"
                  :disabled="isSystemPreset"
                />
              </td>
              <td class="py-3 pr-4 font-medium">Cross-seed</td>
              <td class="py-3 pr-4 text-gray-400 text-xs italic">— (par tracker supplémentaire)</td>
              <td class="py-3">
                <InputNumber
                  v-model="editedCriteria.cross_seed.weight"
                  :min="0"
                  :max="100"
                  :disabled="isSystemPreset || !editedCriteria.cross_seed.enabled"
                  class="w-24"
                  suffix=" pts"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Preview live -->
      <div class="border-t border-gray-200 pt-4 space-y-3">
        <div class="flex items-center gap-4">
          <h4 class="font-medium text-gray-700">Aperçu live (20 premiers)</h4>
          <div class="flex gap-4 text-sm text-gray-500">
            <span>{{ previewStats.count }} résultat(s)</span>
            <span>Score moyen : <strong>{{ previewStats.avgScore }}</strong></span>
            <span>Score &gt; 50 : <strong>{{ previewStats.highScoreCount }}</strong></span>
          </div>
        </div>

        <DataTable
          :value="previewItems"
          dataKey="movie.id"
          :rows="10"
          :paginator="previewItems.length > 10"
          class="text-sm"
          stripedRows
        >
          <Column header="Score" style="width: 5rem">
            <template #body="{ data }">
              <Tag :value="String(data._score)" :severity="scoreColor(data._score)" />
            </template>
          </Column>
          <Column header="Film">
            <template #body="{ data }">
              {{ data.movie.title }}
              <span v-if="data.movie.year" class="text-gray-400 text-xs">({{ data.movie.year }})</span>
            </template>
          </Column>
          <Column header="Ratio" style="width: 6rem">
            <template #body="{ data }">
              <span v-if="data.files.flatMap((f: PreviewItem['files'][0]) => f.torrents).length > 0">
                {{ formatRatio(Math.max(...data.files.flatMap((f: PreviewItem['files'][0]) => f.torrents.map((t) => t.ratio)))) }}
              </span>
              <span v-else class="text-gray-400">—</span>
            </template>
          </Column>
          <Column header="Seed" style="width: 7rem">
            <template #body="{ data }">
              <span v-if="data.files.flatMap((f: PreviewItem['files'][0]) => f.torrents).length > 0">
                {{ formatSeedTime(Math.max(...data.files.flatMap((f: PreviewItem['files'][0]) => f.torrents.map((t) => t.seed_time_seconds)))) }}
              </span>
              <span v-else class="text-gray-400">—</span>
            </template>
          </Column>
          <Column header="Taille" style="width: 7rem">
            <template #body="{ data }">
              {{ formatSize(data.total_freed_bytes) }}
            </template>
          </Column>
        </DataTable>
      </div>
    </div>

    <div v-else class="text-center py-8 text-gray-400">
      Sélectionnez un preset pour modifier ses critères.
    </div>
  </div>
</template>
