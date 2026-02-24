import { useAuthStore } from '@/stores/auth'
import type { UserRole } from '@/types'

export function useAuth() {
  const authStore = useAuthStore()

  function hasMinRole(minRole: UserRole): boolean {
    return authStore.hasMinRole(minRole)
  }

  function isAdmin(): boolean {
    return hasMinRole('ROLE_ADMIN')
  }

  function isAdvancedUser(): boolean {
    return hasMinRole('ROLE_ADVANCED_USER')
  }

  return {
    user: authStore.user,
    isAuthenticated: authStore.isAuthenticated,
    hasMinRole,
    isAdmin,
    isAdvancedUser,
    login: authStore.login,
    logout: authStore.logout,
  }
}
