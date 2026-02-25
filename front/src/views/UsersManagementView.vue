<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useApi } from '@/composables/useApi'
import { useAuthStore } from '@/stores/auth'
import type { User, UserRole, PaginationMeta } from '@/types'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import InputSwitch from 'primevue/inputswitch'
import Message from 'primevue/message'
import ProgressSpinner from 'primevue/progressspinner'
import ConfirmDialog from 'primevue/confirmdialog'
import { useConfirm } from 'primevue/useconfirm'

const api = useApi()
const authStore = useAuthStore()
const confirm = useConfirm()

const users = ref<User[]>([])
const meta = ref<PaginationMeta>({ total: 0, page: 1, limit: 25, total_pages: 0 })
const loading = ref(true)
const error = ref<string | null>(null)
const successMsg = ref<string | null>(null)

// Dialog state
const showDialog = ref(false)
const dialogMode = ref<'create' | 'edit'>('create')
const dialogLoading = ref(false)
const dialogError = ref<string | null>(null)

// Form state
const formEmail = ref('')
const formUsername = ref('')
const formPassword = ref('')
const formRole = ref<UserRole>('ROLE_USER')
const formIsActive = ref(true)
const editingUserId = ref<string | null>(null)

const roleOptions = [
  { label: 'Administrateur', value: 'ROLE_ADMIN' },
  { label: 'Utilisateur avancé', value: 'ROLE_ADVANCED_USER' },
  { label: 'Utilisateur', value: 'ROLE_USER' },
  { label: 'Invité', value: 'ROLE_GUEST' },
]

function roleLabel(role: UserRole): string {
  const labels: Record<UserRole, string> = {
    ROLE_ADMIN: 'Admin',
    ROLE_ADVANCED_USER: 'Avancé',
    ROLE_USER: 'Utilisateur',
    ROLE_GUEST: 'Invité',
  }
  return labels[role] || role
}

function roleSeverity(role: UserRole): 'danger' | 'warn' | 'info' | 'secondary' {
  const severities: Record<UserRole, 'danger' | 'warn' | 'info' | 'secondary'> = {
    ROLE_ADMIN: 'danger',
    ROLE_ADVANCED_USER: 'warn',
    ROLE_USER: 'info',
    ROLE_GUEST: 'secondary',
  }
  return severities[role] || 'secondary'
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

async function fetchUsers(page = 1): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const { data } = await api.get<{ data: User[]; meta: PaginationMeta }>(
      `/users?page=${page}&limit=${meta.value.limit}`,
    )
    users.value = data.data
    meta.value = data.meta
  } catch (err: unknown) {
    const e = err as { response?: { data?: { error?: { message?: string } } } }
    error.value = e.response?.data?.error?.message || 'Impossible de charger les utilisateurs'
  } finally {
    loading.value = false
  }
}

function openCreateDialog(): void {
  dialogMode.value = 'create'
  formEmail.value = ''
  formUsername.value = ''
  formPassword.value = ''
  formRole.value = 'ROLE_USER'
  formIsActive.value = true
  editingUserId.value = null
  dialogError.value = null
  showDialog.value = true
}

function openEditDialog(user: User): void {
  dialogMode.value = 'edit'
  formEmail.value = user.email
  formUsername.value = user.username
  formPassword.value = ''
  formRole.value = user.role
  formIsActive.value = user.is_active
  editingUserId.value = user.id
  dialogError.value = null
  showDialog.value = true
}

async function handleSubmit(): Promise<void> {
  dialogLoading.value = true
  dialogError.value = null

  try {
    if (dialogMode.value === 'create') {
      await api.post('/users', {
        email: formEmail.value,
        username: formUsername.value,
        password: formPassword.value,
        role: formRole.value,
      })
      successMsg.value = 'Utilisateur créé avec succès'
    } else {
      const payload: Record<string, unknown> = {
        email: formEmail.value,
        username: formUsername.value,
        role: formRole.value,
        is_active: formIsActive.value,
      }
      if (formPassword.value) {
        payload.password = formPassword.value
      }
      await api.put(`/users/${editingUserId.value}`, payload)
      successMsg.value = 'Utilisateur modifié avec succès'
    }

    showDialog.value = false
    await fetchUsers(meta.value.page)
  } catch (err: unknown) {
    const e = err as { response?: { data?: { error?: { message?: string } } } }
    dialogError.value = e.response?.data?.error?.message || 'Erreur lors de la sauvegarde'
  } finally {
    dialogLoading.value = false
  }
}

function confirmDelete(user: User): void {
  confirm.require({
    message: `Voulez-vous vraiment supprimer l'utilisateur "${user.username}" (${user.email}) ?`,
    header: 'Confirmer la suppression',
    icon: 'pi pi-exclamation-triangle',
    acceptClass: 'p-button-danger',
    acceptLabel: 'Supprimer',
    rejectLabel: 'Annuler',
    accept: async () => {
      try {
        await api.delete(`/users/${user.id}`)
        successMsg.value = `Utilisateur "${user.username}" supprimé`
        await fetchUsers(meta.value.page)
      } catch (err: unknown) {
        const e = err as { response?: { data?: { error?: { message?: string } } } }
        error.value = e.response?.data?.error?.message || 'Erreur lors de la suppression'
      }
    },
  })
}

function onPageChange(event: { page: number }): void {
  fetchUsers(event.page + 1)
}

onMounted(() => {
  fetchUsers()
})
</script>

<template>
  <div class="space-y-6">
    <ConfirmDialog />

    <!-- Header -->
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-gray-900">Gestion des utilisateurs</h1>
      <Button
        label="Nouvel utilisateur"
        icon="pi pi-plus"
        @click="openCreateDialog"
      />
    </div>

    <!-- Messages -->
    <Message v-if="successMsg" severity="success" :closable="true" @close="successMsg = null">
      {{ successMsg }}
    </Message>
    <Message v-if="error" severity="error" :closable="true" @close="error = null">
      {{ error }}
    </Message>

    <!-- Loading -->
    <div v-if="loading && users.length === 0" class="flex justify-center py-12">
      <ProgressSpinner />
    </div>

    <!-- Users table -->
    <div v-else class="bg-white rounded-lg border border-gray-200">
      <DataTable
        :value="users"
        dataKey="id"
        stripedRows
        :loading="loading"
        :lazy="true"
        :paginator="true"
        :rows="meta.limit"
        :totalRecords="meta.total"
        :first="(meta.page - 1) * meta.limit"
        @page="onPageChange"
        class="text-sm"
      >
        <template #empty>
          <div class="text-center py-8 text-gray-500">
            Aucun utilisateur
          </div>
        </template>

        <Column field="username" header="Nom d'utilisateur" style="min-width: 150px">
          <template #body="{ data }: { data: User }">
            <div class="flex items-center gap-2">
              <span class="font-medium text-gray-900">{{ data.username }}</span>
              <Tag
                v-if="authStore.user?.id === data.id"
                value="Vous"
                severity="info"
                class="text-xs"
              />
            </div>
          </template>
        </Column>

        <Column field="email" header="Email" style="min-width: 200px">
          <template #body="{ data }: { data: User }">
            <span class="text-gray-600">{{ data.email }}</span>
          </template>
        </Column>

        <Column field="role" header="Rôle" style="width: 140px">
          <template #body="{ data }: { data: User }">
            <Tag :value="roleLabel(data.role)" :severity="roleSeverity(data.role)" />
          </template>
        </Column>

        <Column header="Actif" style="width: 80px">
          <template #body="{ data }: { data: User }">
            <i
              :class="data.is_active ? 'pi pi-check-circle text-green-500' : 'pi pi-times-circle text-red-400'"
            ></i>
          </template>
        </Column>

        <Column header="Créé le" style="width: 150px">
          <template #body="{ data }: { data: User }">
            <span class="text-gray-500 text-xs">{{ formatDate(data.created_at) }}</span>
          </template>
        </Column>

        <Column header="Dernière connexion" style="width: 150px">
          <template #body="{ data }: { data: User }">
            <span v-if="data.last_login_at" class="text-gray-500 text-xs">
              {{ formatDate(data.last_login_at) }}
            </span>
            <span v-else class="text-gray-300 text-xs">Jamais</span>
          </template>
        </Column>

        <Column header="Actions" style="width: 120px" frozen alignFrozen="right">
          <template #body="{ data }: { data: User }">
            <div class="flex gap-1">
              <Button
                icon="pi pi-pencil"
                severity="secondary"
                text
                rounded
                size="small"
                v-tooltip.top="'Modifier'"
                @click="openEditDialog(data)"
              />
              <Button
                icon="pi pi-trash"
                severity="danger"
                text
                rounded
                size="small"
                v-tooltip.top="'Supprimer'"
                :disabled="authStore.user?.id === data.id"
                @click="confirmDelete(data)"
              />
            </div>
          </template>
        </Column>
      </DataTable>
    </div>

    <!-- Create/Edit dialog -->
    <Dialog
      v-model:visible="showDialog"
      :modal="true"
      :header="dialogMode === 'create' ? 'Nouvel utilisateur' : 'Modifier l\'utilisateur'"
      :style="{ width: '450px' }"
    >
      <div class="space-y-4">
        <Message v-if="dialogError" severity="error" :closable="false">{{ dialogError }}</Message>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
          <InputText v-model="formEmail" type="email" class="w-full" placeholder="email@exemple.com" />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Nom d'utilisateur</label>
          <InputText v-model="formUsername" class="w-full" placeholder="nom_utilisateur" />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Mot de passe
            <span v-if="dialogMode === 'edit'" class="text-xs text-gray-400">(laisser vide pour ne pas changer)</span>
          </label>
          <InputText
            v-model="formPassword"
            type="password"
            class="w-full"
            :placeholder="dialogMode === 'create' ? 'Minimum 8 caractères' : '••••••••'"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Rôle</label>
          <Select
            v-model="formRole"
            :options="roleOptions"
            optionLabel="label"
            optionValue="value"
            class="w-full"
          />
        </div>

        <div v-if="dialogMode === 'edit'" class="flex items-center gap-3 pt-2 border-t border-gray-200">
          <InputSwitch v-model="formIsActive" inputId="userActive" />
          <label for="userActive" class="cursor-pointer text-sm text-gray-700">Compte actif</label>
        </div>
      </div>

      <template #footer>
        <div class="flex justify-end gap-2">
          <Button label="Annuler" severity="secondary" text @click="showDialog = false" />
          <Button
            :label="dialogMode === 'create' ? 'Créer' : 'Enregistrer'"
            :icon="dialogMode === 'create' ? 'pi pi-plus' : 'pi pi-check'"
            @click="handleSubmit"
            :loading="dialogLoading"
          />
        </div>
      </template>
    </Dialog>
  </div>
</template>
