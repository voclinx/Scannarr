import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import AppSidebar from '@/components/layout/AppSidebar.vue'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'

// Mock router
vi.mock('vue-router', () => ({
  useRouter: () => ({
    push: vi.fn(),
  }),
  useRoute: () => ({
    name: 'dashboard',
    query: {},
    matched: [],
  }),
}))

// Mock useApi
vi.mock('@/composables/useApi', () => ({
  useApi: () => ({
    get: vi.fn(),
    post: vi.fn(),
    delete: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    interceptors: {
      request: { use: vi.fn() },
      response: { use: vi.fn() },
    },
  }),
}))

// Mock router module used by auth store
vi.mock('@/router', () => ({
  default: {
    push: vi.fn(),
  },
}))

// Stub router-link
const stubs = {
  'router-link': {
    template: '<a :href="to"><slot /></a>',
    props: ['to'],
  },
}

const guestUser: User = {
  id: 'u-guest',
  email: 'guest@scanarr.local',
  username: 'guest',
  role: 'ROLE_GUEST',
  is_active: true,
  created_at: '2026-01-01T00:00:00Z',
}

const adminUser: User = {
  id: 'u-admin',
  email: 'admin@scanarr.local',
  username: 'admin',
  role: 'ROLE_ADMIN',
  is_active: true,
  created_at: '2026-01-01T00:00:00Z',
}

describe('AppSidebar', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  // ---------- TEST-FRONT-004 ----------
  it('TEST-FRONT-004: sidebar hides links based on user role', () => {
    const authStore = useAuthStore()

    // Test with ROLE_GUEST: should only see Dashboard, Fichiers, Films
    authStore.setUser(guestUser)
    authStore.setTokens('token', 'refresh')

    const guestWrapper = mount(AppSidebar, {
      global: { stubs },
    })

    const guestLinks = guestWrapper.findAll('a')
    const guestLabels = guestLinks.map((link) => link.text().trim())

    // ROLE_GUEST should see: Dashboard, Fichiers, Films (all minRole=ROLE_GUEST)
    expect(guestLabels).toContain('Dashboard')
    expect(guestLabels).toContain('Fichiers')
    expect(guestLabels).toContain('Films')

    // ROLE_GUEST should NOT see: Suppressions (ROLE_USER), Parametres (ROLE_ADMIN), Utilisateurs (ROLE_ADMIN)
    expect(guestLabels).not.toContain('Suppressions')
    expect(guestLabels).not.toContain('Paramètres')
    expect(guestLabels).not.toContain('Utilisateurs')

    guestWrapper.unmount()

    // Test with ROLE_ADMIN: should see all links
    authStore.setUser(adminUser)

    const adminWrapper = mount(AppSidebar, {
      global: { stubs },
    })

    const adminLinks = adminWrapper.findAll('a')
    const adminLabels = adminLinks.map((link) => link.text().trim())

    // Admin should see everything (excluding the brand link "Scanarr")
    expect(adminLabels).toContain('Dashboard')
    expect(adminLabels).toContain('Fichiers')
    expect(adminLabels).toContain('Films')
    expect(adminLabels).toContain('Suppressions')
    expect(adminLabels).toContain('Paramètres')
    expect(adminLabels).toContain('Utilisateurs')

    adminWrapper.unmount()
  })
})
