<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import InputNumber from 'primevue/inputnumber'
import Message from 'primevue/message'
import { useTrackerRulesStore } from '@/stores/trackerRules'
import type { TrackerRule } from '@/types'

const store = useTrackerRulesStore()

const saving = ref<string | null>(null)
const saveError = ref<string | null>(null)

async function saveRule(rule: TrackerRule): Promise<void> {
  saving.value = rule.id
  saveError.value = null
  try {
    await store.updateRule(rule.id, {
      min_ratio: rule.min_ratio,
      min_seed_time_hours: rule.min_seed_time_hours,
    })
  } catch (err: unknown) {
    const error = err as { response?: { data?: { error?: { message?: string } } } }
    saveError.value = error.response?.data?.error?.message || 'Erreur lors de la sauvegarde'
  } finally {
    saving.value = null
  }
}

onMounted(async () => {
  await store.fetchRules()
})
</script>

<template>
  <div class="space-y-4">
    <h3 class="text-lg font-semibold text-gray-900">Règles tracker</h3>

    <p class="text-sm text-gray-500">
      Ces règles définissent les garde-fous avant suppression. Un fichier ne peut être supprimé que si
      les conditions du tracker sont satisfaites (ratio minimum et seed time minimum).
      Les règles sont détectées automatiquement lors de la synchronisation qBittorrent.
    </p>

    <Message v-if="saveError" severity="error" :closable="true" @close="saveError = null">
      {{ saveError }}
    </Message>

    <DataTable
      :value="store.rules"
      :loading="store.loading"
      dataKey="id"
      stripedRows
      class="text-sm"
    >
      <template #empty>
        <div class="text-center py-8 text-gray-400">
          Aucune règle tracker — lancez une synchronisation qBittorrent pour détecter vos trackers.
        </div>
      </template>

      <!-- Tracker -->
      <Column field="tracker_domain" header="Tracker">
        <template #body="{ data }">
          <span class="font-mono text-sm">{{ data.tracker_domain }}</span>
        </template>
      </Column>

      <!-- Ratio minimum -->
      <Column header="Ratio minimum" style="width: 12rem">
        <template #body="{ data }">
          <InputNumber
            v-model="data.min_ratio"
            :min="0"
            :max="100"
            :minFractionDigits="1"
            :maxFractionDigits="2"
            class="w-full"
            @blur="saveRule(data)"
          />
        </template>
      </Column>

      <!-- Seed time minimum -->
      <Column header="Seed time minimum" style="width: 12rem">
        <template #body="{ data }">
          <InputNumber
            v-model="data.min_seed_time_hours"
            :min="0"
            :max="87600"
            suffix=" h"
            class="w-full"
            @blur="saveRule(data)"
          />
        </template>
      </Column>

      <!-- Auto / Manuel -->
      <Column header="Détection" style="width: 8rem">
        <template #body="{ data }">
          <Tag
            :value="data.is_auto_detected ? 'Auto' : 'Manuel'"
            :severity="data.is_auto_detected ? 'success' : 'secondary'"
          />
        </template>
      </Column>

      <!-- Indicateur sauvegarde -->
      <Column style="width: 4rem">
        <template #body="{ data }">
          <i v-if="saving === data.id" class="pi pi-spin pi-spinner text-blue-500"></i>
        </template>
      </Column>
    </DataTable>
  </div>
</template>
