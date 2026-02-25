import { describe, it, expect, vi, beforeEach } from 'vitest'
import { shallowMount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ScheduledDeletionsView from '@/views/ScheduledDeletionsView.vue'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'

// Mock router
vi.mock('vue-router', () => ({
  useRouter: () => ({
    push: vi.fn(),
  }),
  useRoute: () => ({
    name: 'deletions',
    query: {},
  }),
}))

// Mock useApi
const mockGet = vi.fn()
const mockPost = vi.fn()

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

const advancedUser: User = {
  id: 'u-adv',
  email: 'advanced@scanarr.local',
  username: 'advanced',
  role: 'ROLE_ADVANCED_USER',
  is_active: true,
  created_at: '2026-01-01T00:00:00Z',
}

// Stub PrimeVue components
const globalStubs = {
  DataTable: {
    template: '<div data-testid="data-table"><slot name="empty" /></div>',
    props: ['value', 'loading', 'dataKey', 'stripedRows'],
  },
  Column: {
    template: '<div></div>',
    props: ['header', 'field', 'style'],
  },
  Tag: {
    template: '<span>{{ value }}</span>',
    props: ['value', 'severity'],
  },
  Button: {
    template: '<button @click="$emit(\'click\')" :data-label="label">{{ label }}</button>',
    props: ['label', 'icon', 'loading', 'severity', 'size', 'text', 'rounded', 'disabled'],
    emits: ['click'],
  },
  Dialog: {
    template: '<div v-if="visible" data-testid="dialog"><slot /><slot name="footer" /></div>',
    props: ['visible', 'modal', 'header', 'style'],
    emits: ['update:visible'],
  },
  DatePicker: {
    template: '<input type="date" data-testid="date-picker" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    props: ['modelValue', 'minDate', 'dateFormat', 'placeholder'],
    emits: ['update:modelValue'],
  },
  InputNumber: {
    template: '<input type="number" :value="modelValue" />',
    props: ['modelValue', 'min', 'max'],
  },
  Checkbox: {
    template: '<input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', !modelValue)" />',
    props: ['modelValue', 'binary', 'inputId'],
    emits: ['update:modelValue'],
  },
  Select: {
    template: '<select><option v-for="opt in options" :key="opt.value" :value="opt.value">{{ opt.label }}</option></select>',
    props: ['modelValue', 'options', 'optionLabel', 'optionValue', 'placeholder', 'filter', 'filterPlaceholder', 'appendTo'],
    emits: ['update:modelValue', 'change'],
  },
  Message: {
    template: '<div role="alert"><slot /></div>',
    props: ['severity', 'closable'],
  },
}

describe('ScheduledDeletionsView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  // ---------- TEST-FRONT-010 ----------
  it('TEST-FRONT-010: create dialog validates that date must be selected', async () => {
    // Mock initial fetchDeletions
    mockGet.mockResolvedValue({
      data: {
        data: [],
        meta: { total: 0, page: 1, limit: 25, total_pages: 0 },
      },
    })

    // Set up user with sufficient role to see the "Planifier" button
    const authStore = useAuthStore()
    authStore.setTokens('token', 'refresh')
    authStore.setUser(advancedUser)

    const wrapper = shallowMount(ScheduledDeletionsView, {
      global: {
        stubs: globalStubs,
      },
    })

    await flushPromises()

    // Find and click the "Planifier" button to open the create dialog
    const planButton = wrapper.findAll('button').find((btn) => btn.text() === 'Planifier')
    expect(planButton).toBeDefined()

    await planButton!.trigger('click')
    await wrapper.vm.$nextTick()

    // The dialog should be visible
    const dialog = wrapper.find('[data-testid="dialog"]')
    expect(dialog.exists()).toBe(true)

    // The dialog should show the date picker field
    expect(dialog.text()).toContain('Date de suppression')

    // Find the "Planifier" submit button inside the dialog footer
    // and click it to trigger validation (no date selected, no movies selected)
    const dialogButtons = dialog.findAll('button')
    const submitButton = dialogButtons.find((btn) => {
      const label = btn.attributes('data-label')
      return label === 'Planifier'
    })

    expect(submitButton).toBeDefined()

    // Click the submit button -- the handleCreate function validates:
    //   if (!formDate.value || formSelectedMovies.value.length === 0)
    //     createError.value = 'Sélectionnez une date et au moins un film'
    await submitButton!.trigger('click')
    await flushPromises()

    // Verify the validation error message is displayed
    expect(wrapper.text()).toContain('Sélectionnez une date et au moins un film')
  })
})
