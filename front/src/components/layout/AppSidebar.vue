<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import type { UserRole } from '@/types'

const route = useRoute()
const authStore = useAuthStore()

interface NavItem {
  label: string
  icon: string
  to: string
  routeName: string
  minRole: UserRole
}

const navItems: NavItem[] = [
  { label: 'Dashboard', icon: 'pi pi-home', to: '/', routeName: 'dashboard', minRole: 'ROLE_GUEST' },
  { label: 'Fichiers', icon: 'pi pi-folder', to: '/files', routeName: 'files', minRole: 'ROLE_GUEST' },
  { label: 'Films', icon: 'pi pi-video', to: '/movies', routeName: 'movies', minRole: 'ROLE_GUEST' },
  { label: 'Suggestions', icon: 'pi pi-lightbulb', to: '/suggestions', routeName: 'suggestions', minRole: 'ROLE_USER' },
  { label: 'Suppressions', icon: 'pi pi-calendar-clock', to: '/deletions', routeName: 'deletions', minRole: 'ROLE_USER' },
  { label: 'Paramètres', icon: 'pi pi-cog', to: '/settings', routeName: 'settings', minRole: 'ROLE_ADMIN' },
  { label: 'Utilisateurs', icon: 'pi pi-users', to: '/users', routeName: 'users', minRole: 'ROLE_ADMIN' },
]

const filteredNavItems = computed(() =>
  navItems.filter((item) => authStore.hasMinRole(item.minRole))
)

function isActive(routeName: string): boolean {
  return route.name === routeName
}
</script>

<template>
  <aside class="w-64 bg-gray-900 text-white flex flex-col min-h-screen">
    <div class="p-5 border-b border-gray-700">
      <router-link to="/" class="text-xl font-bold tracking-wide text-white hover:text-blue-400 transition-colors">
        📂 Scanarr
      </router-link>
    </div>

    <nav class="flex-1 py-4">
      <ul class="space-y-1 px-3">
        <li v-for="item in filteredNavItems" :key="item.routeName">
          <router-link
            :to="item.to"
            class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors"
            :class="isActive(item.routeName)
              ? 'bg-blue-600 text-white'
              : 'text-gray-300 hover:bg-gray-800 hover:text-white'"
          >
            <i :class="item.icon" class="text-base"></i>
            {{ item.label }}
          </router-link>
        </li>
      </ul>
    </nav>

    <div class="p-4 border-t border-gray-700 text-xs text-gray-500">
      Scanarr v1.0
    </div>
  </aside>
</template>
