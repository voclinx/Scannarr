<script setup lang="ts">
import { ref, onMounted } from 'vue'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import InputText from 'primevue/inputtext'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useWatchersStore } from '@/stores/watchers'
import { useWatcherSocket } from '@/composables/useWatcherSocket'
import WatcherConfigDialog from './WatcherConfigDialog.vue'
import WatcherLogsDialog from './WatcherLogsDialog.vue'
import type { Watcher, WatcherConfig, WatcherStatus } from '@/types'

const store = useWatchersStore()
const toast = useToast()
const confirm = useConfirm()

// Dialogs
const configDialogVisible = ref(false)
const logsDialogVisible = ref(false)
const selectedWatcher = ref<Watcher | null>(null)

// Inline rename
const renamingId = ref<string | null>(null)
const renameValue = ref('')

// Real-time WebSocket for watcher status updates
useWatcherSocket((event) => {
  if (event.type === 'watcher.status_changed') {
    store.updateWatcherStatus(
      event.data.id,
      event.data.status as WatcherStatus,
      event.data.last_seen_at,
    )
  }
})

onMounted(() => {
  store.fetchWatchers()
})

function statusSeverity(status: string): 'success' | 'info' | 'warn' | 'danger' | 'secondary' {
  switch (status) {
    case 'connected':
      return 'success'
    case 'approved':
    case 'disconnected':
      return 'secondary'
    case 'pending':
      return 'warn'
    case 'revoked':
      return 'danger'
    default:
      return 'secondary'
  }
}

function statusLabel(status: string): string {
  switch (status) {
    case 'connected':
      return 'Connecté'
    case 'approved':
      return 'Approuvé'
    case 'disconnected':
      return 'Déconnecté'
    case 'pending':
      return 'En attente'
    case 'revoked':
      return 'Révoqué'
    default:
      return status
  }
}

function statusIcon(status: string): string {
  switch (status) {
    case 'connected':
      return 'pi pi-circle-fill text-green-500'
    case 'approved':
    case 'disconnected':
      return 'pi pi-circle text-gray-400'
    case 'pending':
      return 'pi pi-clock text-yellow-500'
    case 'revoked':
      return 'pi pi-ban text-red-500'
    default:
      return 'pi pi-circle text-gray-400'
  }
}

function canApprove(w: Watcher): boolean {
  return w.status === 'pending'
}

function canRevoke(w: Watcher): boolean {
  return w.status !== 'revoked' && w.status !== 'pending'
}

function canConfig(w: Watcher): boolean {
  return w.status !== 'pending' && w.status !== 'revoked'
}

function formatDate(iso?: string): string {
  if (!iso) return 'Jamais'
  try {
    return new Date(iso).toLocaleString('fr-FR', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    })
  } catch {
    return iso
  }
}

function openConfig(w: Watcher) {
  selectedWatcher.value = w
  configDialogVisible.value = true
}

function openLogs(w: Watcher) {
  selectedWatcher.value = w
  logsDialogVisible.value = true
}

function startRename(w: Watcher) {
  renamingId.value = w.id
  renameValue.value = w.name || w.watcher_id
}

async function commitRename(w: Watcher) {
  const name = renameValue.value.trim()
  if (!name) {
    renamingId.value = null
    return
  }
  try {
    await store.updateName(w.id, name)
    toast.add({ severity: 'success', summary: 'Watcher renommé', life: 3000 })
  } catch {
    toast.add({ severity: 'error', summary: 'Erreur lors du renommage', life: 3000 })
  } finally {
    renamingId.value = null
  }
}

async function handleApprove(w: Watcher) {
  try {
    await store.approveWatcher(w.id)
    toast.add({ severity: 'success', summary: `${w.name || w.watcher_id} approuvé`, life: 3000 })
    // Refresh after a short delay to pick up "connected" status once the watcher reconnects
    setTimeout(() => store.fetchWatchers(), 2000)
  } catch {
    toast.add({ severity: 'error', summary: "Erreur lors de l'approbation", life: 3000 })
  }
}

async function handleSaveConfig(config: Partial<WatcherConfig>) {
  if (!selectedWatcher.value) return
  try {
    await store.updateConfig(selectedWatcher.value.id, config)
    toast.add({ severity: 'success', summary: 'Configuration enregistrée', life: 3000 })
  } catch {
    toast.add({
      severity: 'error',
      summary: 'Erreur lors de la sauvegarde de la configuration',
      life: 3000,
    })
  }
}

async function handleToggleDebug(w: Watcher) {
  try {
    const updated = await store.toggleDebug(w.id)
    const level = updated.config.log_level
    toast.add({
      severity: 'info',
      summary: `Debug ${level === 'debug' ? 'activé' : 'désactivé'}`,
      life: 3000,
    })
  } catch {
    toast.add({ severity: 'error', summary: 'Erreur lors du basculement debug', life: 3000 })
  }
}

function handleRevoke(w: Watcher) {
  confirm.require({
    message: `Révoquer le watcher "${w.name || w.watcher_id}" ? Il devra être réapprouvé pour se reconnecter.`,
    header: 'Révoquer le watcher',
    icon: 'pi pi-ban',
    acceptLabel: 'Révoquer',
    rejectLabel: 'Annuler',
    accept: async () => {
      try {
        await store.revokeWatcher(w.id)
        toast.add({
          severity: 'warn',
          summary: `${w.name || w.watcher_id} révoqué`,
          life: 3000,
        })
      } catch {
        toast.add({ severity: 'error', summary: 'Erreur lors de la révocation', life: 3000 })
      }
    },
  })
}

function handleDelete(w: Watcher) {
  confirm.require({
    message: `Supprimer définitivement le watcher "${w.name || w.watcher_id}" ?`,
    header: 'Supprimer le watcher',
    icon: 'pi pi-trash',
    acceptLabel: 'Supprimer',
    rejectLabel: 'Annuler',
    accept: async () => {
      try {
        await store.deleteWatcher(w.id)
        toast.add({ severity: 'success', summary: 'Watcher supprimé', life: 3000 })
      } catch {
        toast.add({ severity: 'error', summary: 'Erreur lors de la suppression', life: 3000 })
      }
    },
  })
}
</script>

<template>
  <div class="space-y-4">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-lg font-semibold text-gray-900">Watchers</h2>
        <p class="text-sm text-gray-500">
          Gérez les instances de surveillance de votre système de fichiers.
        </p>
      </div>
      <Button
        icon="pi pi-refresh"
        severity="secondary"
        size="small"
        label="Actualiser"
        :loading="store.loading"
        @click="store.fetchWatchers()"
      />
    </div>

    <!-- Empty state -->
    <div v-if="!store.loading && store.watchers.length === 0" class="text-center py-12 text-gray-400">
      <i class="pi pi-desktop text-4xl mb-3 block"></i>
      <p class="text-sm">Aucun watcher enregistré.</p>
      <p class="text-xs mt-1">Démarrez un watcher sur votre serveur pour qu'il apparaisse ici.</p>
    </div>

    <!-- Cards -->
    <div class="space-y-3">
      <div
        v-for="watcher in store.watchers"
        :key="watcher.id"
        class="border border-gray-200 rounded-lg p-4 bg-white"
      >
        <!-- Card header -->
        <div class="flex items-start justify-between gap-4">
          <div class="flex items-center gap-3 min-w-0">
            <i :class="statusIcon(watcher.status)"></i>

            <!-- Inline rename -->
            <div v-if="renamingId === watcher.id" class="flex items-center gap-2">
              <InputText
                v-model="renameValue"
                class="text-sm h-7 py-1"
                autofocus
                @keydown.enter="commitRename(watcher)"
                @keydown.escape="renamingId = null"
              />
              <Button
                icon="pi pi-check"
                size="small"
                class="p-1 h-7 w-7"
                @click="commitRename(watcher)"
              />
              <Button
                icon="pi pi-times"
                severity="secondary"
                size="small"
                class="p-1 h-7 w-7"
                @click="renamingId = null"
              />
            </div>
            <div v-else class="min-w-0">
              <div class="flex items-center gap-2">
                <span class="font-semibold text-gray-900 truncate">
                  {{ watcher.name || watcher.watcher_id }}
                </span>
                <button
                  class="text-gray-400 hover:text-gray-600"
                  title="Renommer"
                  @click="startRename(watcher)"
                >
                  <i class="pi pi-pencil text-xs"></i>
                </button>
              </div>
              <span class="text-xs text-gray-500 font-mono">{{ watcher.watcher_id }}</span>
            </div>
          </div>

          <div class="flex items-center gap-2 shrink-0">
            <Tag :severity="statusSeverity(watcher.status)" :value="statusLabel(watcher.status)" />
          </div>
        </div>

        <!-- Info line -->
        <div class="mt-2 flex flex-wrap gap-4 text-xs text-gray-500">
          <span v-if="watcher.hostname">
            <i class="pi pi-server mr-1"></i>{{ watcher.hostname }}
          </span>
          <span v-if="watcher.version">
            <i class="pi pi-tag mr-1"></i>{{ watcher.version }}
          </span>
          <span>
            <i class="pi pi-eye mr-1"></i>
            {{ watcher.config.watch_paths.length }} chemin(s)
          </span>
          <span>
            <i class="pi pi-clock mr-1"></i>
            Vu le {{ formatDate(watcher.last_seen_at) }}
          </span>
          <span v-if="watcher.config.log_level === 'debug'" class="text-yellow-600 font-semibold">
            <i class="pi pi-exclamation-triangle mr-1"></i>Debug actif
          </span>
        </div>

        <!-- Actions -->
        <div class="mt-3 flex flex-wrap gap-2">
          <!-- Approve -->
          <Button
            v-if="canApprove(watcher)"
            label="Approuver"
            icon="pi pi-check-circle"
            size="small"
            severity="success"
            @click="handleApprove(watcher)"
          />

          <!-- Config -->
          <Button
            v-if="canConfig(watcher)"
            label="Configuration"
            icon="pi pi-cog"
            size="small"
            severity="secondary"
            @click="openConfig(watcher)"
          />

          <!-- Debug toggle -->
          <Button
            v-if="canConfig(watcher)"
            :label="watcher.config.log_level === 'debug' ? 'Désactiver debug' : 'Debug'"
            :icon="watcher.config.log_level === 'debug' ? 'pi pi-eye-slash' : 'pi pi-bug'"
            size="small"
            :severity="watcher.config.log_level === 'debug' ? 'warn' : 'secondary'"
            @click="handleToggleDebug(watcher)"
          />

          <!-- Logs -->
          <Button
            label="Logs"
            icon="pi pi-list"
            size="small"
            severity="secondary"
            @click="openLogs(watcher)"
          />

          <!-- Revoke -->
          <Button
            v-if="canRevoke(watcher)"
            label="Révoquer"
            icon="pi pi-ban"
            size="small"
            severity="danger"
            outlined
            @click="handleRevoke(watcher)"
          />

          <!-- Delete -->
          <Button
            label="Supprimer"
            icon="pi pi-trash"
            size="small"
            severity="danger"
            text
            @click="handleDelete(watcher)"
          />
        </div>
      </div>
    </div>

    <!-- Config dialog -->
    <WatcherConfigDialog
      v-if="selectedWatcher && configDialogVisible"
      v-model:visible="configDialogVisible"
      :watcher="selectedWatcher"
      @save="handleSaveConfig"
    />

    <!-- Logs dialog -->
    <WatcherLogsDialog
      v-if="selectedWatcher && logsDialogVisible"
      v-model:visible="logsDialogVisible"
      :watcher-id="selectedWatcher.id"
      :watcher-name="selectedWatcher.name || selectedWatcher.watcher_id"
    />
  </div>
</template>
