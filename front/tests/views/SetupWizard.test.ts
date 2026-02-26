import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import SetupWizardView from '@/views/SetupWizardView.vue'

// Mock router
const mockPush = vi.fn()

vi.mock('vue-router', () => ({
  useRouter: () => ({
    push: mockPush,
  }),
  useRoute: () => ({
    query: {},
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

// Stub PrimeVue components
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
    template: '<div role="alert" :data-severity="severity"><slot /></div>',
    props: ['severity', 'closable'],
  },
}

describe('SetupWizardView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  // ---------- TEST-FRONT-003 ----------
  it('TEST-FRONT-003: displays admin creation form with all required fields', () => {
    const wrapper = mount(SetupWizardView, {
      global: {
        stubs,
      },
    })

    // Verify the heading
    expect(wrapper.text()).toContain('Configuration initiale')
    expect(wrapper.text()).toContain('Créez le compte administrateur')

    // Verify form is visible (not hidden by success state)
    const form = wrapper.find('form')
    expect(form.exists()).toBe(true)

    // Should have 4 input fields: username, email, password, confirmPassword
    const inputs = wrapper.findAll('input')
    expect(inputs.length).toBe(4)

    // Verify labels are present
    expect(wrapper.text()).toContain("Nom d'utilisateur")
    expect(wrapper.text()).toContain('Email')
    expect(wrapper.text()).toContain('Mot de passe')
    expect(wrapper.text()).toContain('Confirmer le mot de passe')

    // Verify submit button
    expect(wrapper.text()).toContain('Créer le compte')
  })
})
