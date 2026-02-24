import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type { User, UserRole, LoginResponse } from '@/types'
import { useApi } from '@/composables/useApi'
import router from '@/router'

const ROLE_HIERARCHY: UserRole[] = ['ROLE_GUEST', 'ROLE_USER', 'ROLE_ADVANCED_USER', 'ROLE_ADMIN']

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const accessToken = ref<string | null>(localStorage.getItem('access_token'))
  const refreshToken = ref<string | null>(localStorage.getItem('refresh_token'))
  const loading = ref(false)

  const isAuthenticated = computed(() => !!accessToken.value && !!user.value)

  function setTokens(access: string, refresh: string) {
    accessToken.value = access
    refreshToken.value = refresh
    localStorage.setItem('access_token', access)
    localStorage.setItem('refresh_token', refresh)
  }

  function clearAuth() {
    user.value = null
    accessToken.value = null
    refreshToken.value = null
    localStorage.removeItem('access_token')
    localStorage.removeItem('refresh_token')
  }

  function setUser(u: User) {
    user.value = u
  }

  function hasMinRole(minRole: UserRole): boolean {
    if (!user.value) return false
    const userLevel = ROLE_HIERARCHY.indexOf(user.value.role)
    const requiredLevel = ROLE_HIERARCHY.indexOf(minRole)
    return userLevel >= requiredLevel
  }

  async function login(email: string, password: string): Promise<void> {
    loading.value = true
    try {
      const api = useApi()
      const response = await api.post<{ data: LoginResponse }>('/auth/login', { email, password })
      const { access_token, refresh_token, user: loginUser } = response.data.data
      setTokens(access_token, refresh_token)
      await fetchMe()
      // loginUser is a subset — fetchMe gets the full user
      void loginUser
    } finally {
      loading.value = false
    }
  }

  async function logout(): Promise<void> {
    clearAuth()
    await router.push({ name: 'login' })
  }

  async function refreshAccessToken(): Promise<void> {
    if (!refreshToken.value) {
      clearAuth()
      return
    }
    try {
      const api = useApi()
      const response = await api.post<{ data: { access_token: string; refresh_token: string } }>('/auth/refresh', {
        refresh_token: refreshToken.value,
      })
      const { access_token, refresh_token } = response.data.data
      setTokens(access_token, refresh_token)
    } catch {
      clearAuth()
      await router.push({ name: 'login' })
    }
  }

  async function fetchMe(): Promise<void> {
    try {
      const api = useApi()
      const response = await api.get<{ data: User }>('/auth/me')
      setUser(response.data.data)
    } catch {
      clearAuth()
    }
  }

  async function setup(email: string, username: string, password: string): Promise<void> {
    loading.value = true
    try {
      const api = useApi()
      await api.post('/auth/setup', { email, username, password })
    } finally {
      loading.value = false
    }
  }

  async function checkSetupRequired(): Promise<boolean> {
    try {
      const api = useApi()
      await api.post('/auth/setup', {})
      // If it succeeds with empty body, shouldn't happen
      return true
    } catch (error: unknown) {
      if (error && typeof error === 'object' && 'response' in error) {
        const axiosError = error as { response: { status: number } }
        if (axiosError.response.status === 403) {
          return false // setup already completed
        }
      }
      return true // assume setup needed on other errors
    }
  }

  return {
    user,
    accessToken,
    refreshToken,
    loading,
    isAuthenticated,
    setTokens,
    clearAuth,
    setUser,
    hasMinRole,
    login,
    logout,
    refreshAccessToken,
    fetchMe,
    setup,
    checkSetupRequired,
  }
})
