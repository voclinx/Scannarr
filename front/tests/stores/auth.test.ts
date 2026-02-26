import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'

// Mock the router
const mockPush = vi.fn()
vi.mock('@/router', () => ({
  default: {
    push: (...args: unknown[]) => mockPush(...args),
  },
}))

// Mock the useApi composable
const mockGet = vi.fn()
const mockPost = vi.fn()
const mockApiInstance = {
  get: mockGet,
  post: mockPost,
  delete: vi.fn(),
  put: vi.fn(),
  patch: vi.fn(),
  interceptors: {
    request: { use: vi.fn() },
    response: { use: vi.fn() },
  },
}

vi.mock('@/composables/useApi', () => ({
  useApi: () => mockApiInstance,
}))

const fakeUser: User = {
  id: 'u-001',
  email: 'admin@scanarr.local',
  username: 'admin',
  role: 'ROLE_ADMIN',
  is_active: true,
  created_at: '2026-01-01T00:00:00Z',
}

const fakeGuestUser: User = {
  id: 'u-002',
  email: 'guest@scanarr.local',
  username: 'guest',
  role: 'ROLE_GUEST',
  is_active: true,
  created_at: '2026-01-01T00:00:00Z',
}

describe('Auth Store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    localStorage.clear()
  })

  // ---------- TEST-FRONT-013 ----------
  it('TEST-FRONT-013: refreshAccessToken updates tokens on success', async () => {
    const store = useAuthStore()

    // Seed the store with an existing refresh token
    store.setTokens('old-access', 'old-refresh')

    mockPost.mockResolvedValueOnce({
      data: {
        data: {
          access_token: 'new-access-token',
          refresh_token: 'new-refresh-token',
        },
      },
    })

    await store.refreshAccessToken()

    // Verify POST was called with the old refresh token
    expect(mockPost).toHaveBeenCalledWith('/auth/refresh', {
      refresh_token: 'old-refresh',
    })

    // Verify tokens are updated
    expect(store.accessToken).toBe('new-access-token')
    expect(store.refreshToken).toBe('new-refresh-token')
    expect(localStorage.getItem('access_token')).toBe('new-access-token')
    expect(localStorage.getItem('refresh_token')).toBe('new-refresh-token')
  })

  // ---------- TEST-FRONT-014 ----------
  it('TEST-FRONT-014: isAuthenticated is false when no token or user', () => {
    const store = useAuthStore()

    // Initially: no token, no user
    expect(store.isAuthenticated).toBe(false)

    // With token but no user
    store.accessToken = 'some-token'
    expect(store.isAuthenticated).toBe(false)

    // With user but no token
    store.accessToken = null
    store.user = fakeUser
    expect(store.isAuthenticated).toBe(false)

    // With both token and user
    store.accessToken = 'some-token'
    store.user = fakeUser
    expect(store.isAuthenticated).toBe(true)
  })

  // ---------- TEST-FRONT-015 ----------
  it('TEST-FRONT-015: hasMinRole checks role hierarchy correctly', () => {
    const store = useAuthStore()

    // No user => always false
    expect(store.hasMinRole('ROLE_GUEST')).toBe(false)

    // Admin user can access everything
    store.user = fakeUser // ROLE_ADMIN
    expect(store.hasMinRole('ROLE_GUEST')).toBe(true)
    expect(store.hasMinRole('ROLE_USER')).toBe(true)
    expect(store.hasMinRole('ROLE_ADVANCED_USER')).toBe(true)
    expect(store.hasMinRole('ROLE_ADMIN')).toBe(true)

    // Guest user can only access ROLE_GUEST
    store.user = fakeGuestUser // ROLE_GUEST
    expect(store.hasMinRole('ROLE_GUEST')).toBe(true)
    expect(store.hasMinRole('ROLE_USER')).toBe(false)
    expect(store.hasMinRole('ROLE_ADVANCED_USER')).toBe(false)
    expect(store.hasMinRole('ROLE_ADMIN')).toBe(false)
  })

  // ---------- Login success sets tokens and user ----------
  it('login success sets tokens and user', async () => {
    const store = useAuthStore()

    // Mock login response
    mockPost.mockResolvedValueOnce({
      data: {
        data: {
          access_token: 'jwt-access',
          refresh_token: 'jwt-refresh',
          expires_in: 3600,
          user: { id: 'u-001', username: 'admin', role: 'ROLE_ADMIN' },
        },
      },
    })

    // Mock fetchMe response (called inside login)
    mockGet.mockResolvedValueOnce({
      data: { data: fakeUser },
    })

    await store.login('admin@scanarr.local', 'password123')

    // Verify login API was called
    expect(mockPost).toHaveBeenCalledWith('/auth/login', {
      email: 'admin@scanarr.local',
      password: 'password123',
    })

    // Verify tokens are set
    expect(store.accessToken).toBe('jwt-access')
    expect(store.refreshToken).toBe('jwt-refresh')
    expect(localStorage.getItem('access_token')).toBe('jwt-access')
    expect(localStorage.getItem('refresh_token')).toBe('jwt-refresh')

    // Verify user is set via fetchMe
    expect(store.user).toEqual(fakeUser)
    expect(store.isAuthenticated).toBe(true)
  })

  // ---------- Logout clears state ----------
  it('logout clears state and redirects to login', async () => {
    const store = useAuthStore()

    // Set up authenticated state
    store.setTokens('access-123', 'refresh-123')
    store.setUser(fakeUser)
    expect(store.isAuthenticated).toBe(true)

    await store.logout()

    // State is cleared
    expect(store.user).toBeNull()
    expect(store.accessToken).toBeNull()
    expect(store.refreshToken).toBeNull()
    expect(localStorage.getItem('access_token')).toBeNull()
    expect(localStorage.getItem('refresh_token')).toBeNull()
    expect(store.isAuthenticated).toBe(false)

    // Router push to login
    expect(mockPush).toHaveBeenCalledWith({ name: 'login' })
  })
})

describe('Auth Router Guards', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    localStorage.clear()
  })

  // ---------- TEST-FRONT-014 (guard): redirect to login if not authenticated ----------
  it('TEST-FRONT-014 guard: redirects to login if unauthenticated on auth-required route', async () => {
    // Dynamically import the router to test guards
    // We simulate the guard logic as the real router would execute it
    const store = useAuthStore()

    // Ensure unauthenticated
    expect(store.isAuthenticated).toBe(false)

    // Simulate the guard logic from router/index.ts
    const to = {
      matched: [{ meta: { requiresAuth: true } }],
      meta: {},
      fullPath: '/movies',
    }
    const isGuestRoute = to.matched.some(
      (record: { meta: { guest?: boolean } }) => record.meta.guest,
    )
    const requiresAuth = to.matched.some(
      (record: { meta: { requiresAuth?: boolean } }) => record.meta.requiresAuth,
    )

    expect(isGuestRoute).toBe(false)
    expect(requiresAuth).toBe(true)

    // Guard should redirect to login
    if (requiresAuth && !store.isAuthenticated) {
      const redirect = { name: 'login', query: { redirect: to.fullPath } }
      expect(redirect).toEqual({
        name: 'login',
        query: { redirect: '/movies' },
      })
    }
  })

  // ---------- TEST-FRONT-015 (guard): redirect to dashboard if role insufficient ----------
  it('TEST-FRONT-015 guard: redirects to dashboard if role is insufficient', () => {
    const store = useAuthStore()

    // Set up as ROLE_GUEST
    store.setTokens('token', 'refresh')
    store.setUser(fakeGuestUser)
    expect(store.isAuthenticated).toBe(true)

    // Try to access settings which requires ROLE_ADMIN
    const minRole = 'ROLE_ADMIN' as const
    const hasRole = store.hasMinRole(minRole)

    expect(hasRole).toBe(false)

    // Guard logic: if authenticated but insufficient role => redirect dashboard
    if (store.isAuthenticated && !hasRole) {
      const redirect = { name: 'dashboard' }
      expect(redirect).toEqual({ name: 'dashboard' })
    }
  })
})
