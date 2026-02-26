import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import LoginView from '@/views/LoginView.vue'

// Mock router
const mockPush = vi.fn()
const mockRouteQuery = { redirect: undefined as string | undefined }

vi.mock('vue-router', () => ({
  useRouter: () => ({
    push: mockPush,
  }),
  useRoute: () => ({
    query: mockRouteQuery,
  }),
}))

// Mock useApi
const mockPost = vi.fn()
const mockGet = vi.fn()

vi.mock('@/composables/useApi', () => ({
  useApi: () => ({
    get: mockGet,
    post: mockPost,
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

// Stub PrimeVue components to avoid rendering issues
const stubs = {
  InputText: {
    template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    props: ['modelValue'],
    emits: ['update:modelValue'],
  },
  Password: {
    template: '<input type="password" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    props: ['modelValue'],
    emits: ['update:modelValue'],
  },
  Button: {
    template: '<button type="submit" @click="$emit(\'click\')"><slot />{{ label }}</button>',
    props: ['label', 'loading', 'icon', 'type'],
    emits: ['click'],
  },
  Message: {
    template: '<div role="alert"><slot /></div>',
    props: ['severity', 'closable'],
  },
}

describe('LoginView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    mockRouteQuery.redirect = undefined
  })

  // ---------- TEST-FRONT-001 ----------
  it('TEST-FRONT-001: successful login redirects to dashboard', async () => {
    // Mock successful login
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

    // Mock fetchMe
    mockGet.mockResolvedValueOnce({
      data: {
        data: {
          id: 'u-001',
          email: 'admin@scanarr.local',
          username: 'admin',
          role: 'ROLE_ADMIN',
          is_active: true,
          created_at: '2026-01-01T00:00:00Z',
        },
      },
    })

    const wrapper = mount(LoginView, {
      global: {
        stubs,
      },
    })

    // Fill in email and password
    const inputs = wrapper.findAll('input')
    await inputs[0]!.setValue('admin@scanarr.local')
    await inputs[1]!.setValue('password123')

    // Submit form
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    // Verify login API was called
    expect(mockPost).toHaveBeenCalledWith('/auth/login', {
      email: 'admin@scanarr.local',
      password: 'password123',
    })

    // Verify redirect to dashboard (no redirect query)
    expect(mockPush).toHaveBeenCalledWith({ name: 'dashboard' })
  })

  // ---------- TEST-FRONT-002 ----------
  it('TEST-FRONT-002: displays error message on invalid credentials', async () => {
    // Mock failed login
    mockPost.mockRejectedValueOnce({
      response: {
        data: {
          error: {
            message: 'Identifiants invalides',
          },
        },
      },
    })

    const wrapper = mount(LoginView, {
      global: {
        stubs,
      },
    })

    // Fill in email and password
    const inputs = wrapper.findAll('input')
    await inputs[0]!.setValue('wrong@email.com')
    await inputs[1]!.setValue('wrongpassword')

    // Submit form
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    // Verify error message is displayed
    const alert = wrapper.find('[role="alert"]')
    expect(alert.exists()).toBe(true)
    expect(alert.text()).toContain('Identifiants invalides')
  })
})
