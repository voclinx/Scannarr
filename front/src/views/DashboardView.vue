<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useApi } from '@/composables/useApi'
import type { DashboardStats } from '@/types'
import ProgressBar from 'primevue/progressbar'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import ProgressSpinner from 'primevue/progressspinner'
import Message from 'primevue/message'

const api = useApi()
const stats = ref<DashboardStats | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

function formatSize(bytes: number): string {
  if (bytes === 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']
  const k = 1024
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return (bytes / Math.pow(k, i)).toFixed(i > 1 ? 1 : 0) + ' ' + units[i]
}

function volumeUsagePercent(used: number, total: number): number {
  if (total === 0) return 0
  return Math.round((used / total) * 100)
}

function actionLabel(action: string): string {
  const labels: Record<string, string> = {
    'file.deleted': 'Fichier supprimé',
    'file.deleted_global': 'Suppression globale',
    'movie.deleted': 'Film supprimé',
    'deletion.scheduled': 'Suppression planifiée',
    'deletion.executed': 'Suppression exécutée',
    'deletion.cancelled': 'Suppression annulée',
    'settings.updated': 'Paramètres modifiés',
    'volume.created': 'Volume créé',
    'volume.deleted': 'Volume supprimé',
    'radarr.synced': 'Sync Radarr',
    'user.created': 'Utilisateur créé',
    'user.updated': 'Utilisateur modifié',
  }
  return labels[action] || action
}

function actionSeverity(action: string): 'success' | 'info' | 'warn' | 'danger' | 'secondary' {
  if (action.includes('deleted') || action.includes('delete')) return 'danger'
  if (action.includes('created') || action.includes('synced')) return 'success'
  if (action.includes('scheduled') || action.includes('cancelled')) return 'warn'
  return 'info'
}

function formatDate(iso: string): string {
  const d = new Date(iso)
  return d.toLocaleDateString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

const totalSizeFormatted = computed(() => {
  if (!stats.value) return '0 B'
  return formatSize(stats.value.total_size_bytes)
})

onMounted(async () => {
  try {
    const { data } = await api.get<{ data: DashboardStats }>('/dashboard')
    stats.value = {
      ...data.data,
      recent_activity: data.data.recent_activity.map((a: DashboardStats['recent_activity'][0], i: number) => ({ ...a, _idx: i })),
    }
  } catch (err: unknown) {
    const e = err as { response?: { data?: { error?: { message?: string } } } }
    error.value = e.response?.data?.error?.message || 'Impossible de charger le tableau de bord'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">Tableau de bord</h1>

    <!-- Loading -->
    <div v-if="loading" class="flex justify-center py-12">
      <ProgressSpinner />
    </div>

    <!-- Error -->
    <Message v-else-if="error" severity="error" :closable="false">{{ error }}</Message>

    <!-- Content -->
    <template v-else-if="stats">
      <!-- Stats cards -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
        <!-- Total movies -->
        <div class="bg-white rounded-lg border border-gray-200 p-5">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
              <i class="pi pi-video text-blue-600"></i>
            </div>
            <div>
              <p class="text-sm text-gray-500">Films</p>
              <p class="text-2xl font-bold text-gray-900">{{ stats.total_movies }}</p>
            </div>
          </div>
        </div>

        <!-- Total files -->
        <div class="bg-white rounded-lg border border-gray-200 p-5">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-purple-100 flex items-center justify-center">
              <i class="pi pi-file text-purple-600"></i>
            </div>
            <div>
              <p class="text-sm text-gray-500">Fichiers</p>
              <p class="text-2xl font-bold text-gray-900">{{ stats.total_files }}</p>
            </div>
          </div>
        </div>

        <!-- Total size -->
        <div class="bg-white rounded-lg border border-gray-200 p-5">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-green-100 flex items-center justify-center">
              <i class="pi pi-database text-green-600"></i>
            </div>
            <div>
              <p class="text-sm text-gray-500">Espace utilisé</p>
              <p class="text-2xl font-bold text-gray-900">{{ totalSizeFormatted }}</p>
            </div>
          </div>
        </div>

        <!-- Orphan files -->
        <div
          class="rounded-lg border p-5"
          :class="stats.orphan_files_count > 0 ? 'bg-orange-50 border-orange-200' : 'bg-white border-gray-200'"
        >
          <div class="flex items-center gap-3">
            <div
              class="w-10 h-10 rounded-lg flex items-center justify-center"
              :class="stats.orphan_files_count > 0 ? 'bg-orange-100' : 'bg-gray-100'"
            >
              <i
                class="pi pi-exclamation-triangle"
                :class="stats.orphan_files_count > 0 ? 'text-orange-600' : 'text-gray-400'"
              ></i>
            </div>
            <div>
              <p class="text-sm text-gray-500">Fichiers orphelins</p>
              <p class="text-2xl font-bold text-gray-900">{{ stats.orphan_files_count }}</p>
            </div>
          </div>
        </div>

        <!-- Upcoming deletions -->
        <div
          class="rounded-lg border p-5"
          :class="stats.upcoming_deletions_count > 0 ? 'bg-red-50 border-red-200' : 'bg-white border-gray-200'"
        >
          <div class="flex items-center gap-3">
            <div
              class="w-10 h-10 rounded-lg flex items-center justify-center"
              :class="stats.upcoming_deletions_count > 0 ? 'bg-red-100' : 'bg-gray-100'"
            >
              <i
                class="pi pi-calendar-times"
                :class="stats.upcoming_deletions_count > 0 ? 'text-red-600' : 'text-gray-400'"
              ></i>
            </div>
            <div>
              <p class="text-sm text-gray-500">Suppressions à venir</p>
              <p class="text-2xl font-bold text-gray-900">{{ stats.upcoming_deletions_count }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Volumes -->
      <div v-if="stats.volumes.length > 0" class="bg-white rounded-lg border border-gray-200 p-5">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Volumes</h2>
        <div class="space-y-4">
          <div
            v-for="vol in stats.volumes"
            :key="vol.id"
            class="flex items-center gap-4"
          >
            <div class="w-32 flex-shrink-0">
              <p class="font-medium text-gray-900 text-sm truncate" :title="vol.name">{{ vol.name }}</p>
              <p class="text-xs text-gray-400">{{ vol.file_count }} fichiers</p>
            </div>
            <div class="flex-1">
              <ProgressBar
                :value="volumeUsagePercent(vol.used_space_bytes, vol.total_space_bytes)"
                :showValue="false"
                style="height: 8px"
              />
            </div>
            <div class="w-44 text-right flex-shrink-0">
              <span class="text-sm text-gray-600">
                {{ formatSize(vol.used_space_bytes) }} / {{ formatSize(vol.total_space_bytes) }}
              </span>
              <span class="text-xs text-gray-400 ml-1">
                ({{ volumeUsagePercent(vol.used_space_bytes, vol.total_space_bytes) }}%)
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Recent activity -->
      <div class="bg-white rounded-lg border border-gray-200 p-5">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Activité récente</h2>

        <DataTable
          :value="stats.recent_activity"
          dataKey="_idx"
          stripedRows
          class="text-sm"
          :rows="10"
          :paginator="stats.recent_activity.length > 10"
        >
          <template #empty>
            <div class="text-center py-4 text-gray-500">
              Aucune activité récente
            </div>
          </template>

          <Column header="Action" style="width: 200px">
            <template #body="{ data }">
              <Tag :value="actionLabel(data.action)" :severity="actionSeverity(data.action)" />
            </template>
          </Column>

          <Column header="Détails" style="min-width: 250px">
            <template #body="{ data }">
              <span class="text-gray-700 text-sm">
                <template v-if="data.details?.file_name">{{ data.details.file_name }}</template>
                <template v-else-if="data.details?.title">{{ data.details.title }}</template>
                <template v-else-if="data.details?.volume_name">{{ data.details.volume_name }}</template>
                <template v-else-if="data.details?.username">{{ data.details.username }}</template>
                <template v-else>—</template>
              </span>
            </template>
          </Column>

          <Column field="user" header="Utilisateur" style="width: 130px">
            <template #body="{ data }">
              <span class="text-gray-600">{{ data.user }}</span>
            </template>
          </Column>

          <Column header="Date" style="width: 160px">
            <template #body="{ data }">
              <span class="text-gray-500 text-xs">{{ formatDate(data.created_at) }}</span>
            </template>
          </Column>
        </DataTable>
      </div>
    </template>
  </div>
</template>
