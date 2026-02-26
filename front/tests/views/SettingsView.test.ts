import { describe, it, expect, vi, beforeEach } from 'vitest'
import { shallowMount, mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import SettingsView from '@/views/SettingsView.vue'
import RadarrSettings from '@/components/settings/RadarrSettings.vue'
import { useSettingsStore } from '@/stores/settings'
import type { RadarrInstance } from '@/types'

// Mock router
vi.mock('vue-router', () => ({
  useRouter: () => ({
    push: vi.fn(),
  }),
  useRoute: () => ({
    name: 'settings',
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

// PrimeVue Tabs stubs
const tabsStubs = {
  Tabs: {
    template: '<div data-testid="tabs"><slot /></div>',
    props: ['value', 'lazy'],
    emits: ['update:value'],
  },
  TabList: {
    template: '<div data-testid="tab-list" role="tablist"><slot /></div>',
  },
  Tab: {
    template: '<button role="tab" :data-value="value" @click="$emit(\'click\')"><slot /></button>',
    props: ['value'],
    emits: ['click'],
  },
  TabPanels: {
    template: '<div data-testid="tab-panels"><slot /></div>',
  },
  TabPanel: {
    template: '<div data-testid="tab-panel" :data-value="value"><slot /></div>',
    props: ['value'],
  },
  RadarrSettings: {
    template: '<div data-testid="radarr-settings">Radarr Settings Component</div>',
  },
  MediaPlayerSettings: {
    template: '<div data-testid="media-player-settings">Media Player Settings</div>',
  },
  VolumeSettings: {
    template: '<div data-testid="volume-settings">Volume Settings</div>',
  },
  TorrentSettings: {
    template: '<div data-testid="torrent-settings">Torrent Settings</div>',
  },
  DiscordSettings: {
    template: '<div data-testid="discord-settings">Discord Settings</div>',
  },
  GeneralSettings: {
    template: '<div data-testid="general-settings">General Settings</div>',
  },
}

describe('SettingsView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  // ---------- TEST-FRONT-011 ----------
  it('TEST-FRONT-011: tabs navigate between settings components', () => {
    const wrapper = shallowMount(SettingsView, {
      global: {
        stubs: tabsStubs,
      },
    })

    // Verify heading
    expect(wrapper.text()).toContain('Paramètres')

    // Verify all 6 tabs are rendered (Radarr, Lecteurs, Volumes, Torrent, Discord, TMDB)
    const tabs = wrapper.findAll('[role="tab"]')
    expect(tabs.length).toBe(6)

    // Verify tab labels
    const tabLabels = tabs.map((tab) => tab.text().trim())
    expect(tabLabels).toContain('Radarr')
    expect(tabLabels).toContain('Lecteurs')
    expect(tabLabels).toContain('Volumes')
    expect(tabLabels).toContain('Torrent')
    expect(tabLabels).toContain('Discord')
    expect(tabLabels).toContain('TMDB')

    // Verify all tab panels exist
    const panels = wrapper.findAll('[data-testid="tab-panel"]')
    expect(panels.length).toBe(6)

    // Verify each settings component is rendered
    expect(wrapper.find('[data-testid="radarr-settings"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="media-player-settings"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="volume-settings"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="torrent-settings"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="discord-settings"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="general-settings"]').exists()).toBe(true)
  })
})

describe('RadarrSettings', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  // ---------- TEST-FRONT-012 ----------
  it('TEST-FRONT-012: test connection displays success or error', async () => {
    const fakeInstance: RadarrInstance = {
      id: 'radarr-1',
      name: 'Radarr 4K',
      url: 'http://192.168.1.10:7878',
      api_key: 'test-api-key',
      is_active: true,
      last_sync_at: null as unknown as undefined,
    }

    // Mock fetchRadarrInstances
    mockGet.mockResolvedValueOnce({
      data: { data: [fakeInstance] },
    })

    const radarrStubs = {
      DataTable: {
        template: `
          <div data-testid="radarr-table">
            <slot name="empty" />
            <template v-for="row in value" :key="row.id">
              <slot :data="row" />
            </template>
          </div>
        `,
        props: ['value', 'loading', 'dataKey', 'stripedRows'],
      },
      Column: {
        template: '<div><slot :data="$parent.$attrs.row || {}" /></div>',
        props: ['field', 'header', 'style'],
      },
      Tag: {
        template: '<span data-testid="tag">{{ value }}</span>',
        props: ['value', 'severity'],
      },
      Button: {
        template: '<button @click="$emit(\'click\')" :data-icon="icon">{{ label }}</button>',
        props: ['label', 'icon', 'size', 'text', 'rounded', 'severity'],
        emits: ['click'],
      },
      Dialog: {
        template: '<div v-if="visible"><slot /><slot name="footer" /></div>',
        props: ['visible', 'modal', 'header', 'style'],
      },
      InputText: {
        template: '<input />',
        props: ['modelValue'],
      },
      Checkbox: {
        template: '<input type="checkbox" />',
        props: ['modelValue', 'binary', 'inputId'],
      },
      Message: {
        template: '<div role="alert"><slot /></div>',
        props: ['severity', 'closable'],
      },
    }

    const wrapper = shallowMount(RadarrSettings, {
      global: {
        stubs: radarrStubs,
        directives: {
          tooltip: {},
        },
      },
    })

    await flushPromises()

    // Pre-populate the store with the instance
    const settingsStore = useSettingsStore()
    settingsStore.radarrInstances = [fakeInstance]

    // Test successful connection
    mockPost.mockResolvedValueOnce({
      data: {
        data: {
          success: true,
          version: '5.2.0',
          movies_count: 150,
        },
      },
    })

    const result = await settingsStore.testRadarrConnection('radarr-1')

    expect(mockPost).toHaveBeenCalledWith('/radarr-instances/radarr-1/test')
    expect(result.success).toBe(true)
    expect(result.version).toBe('5.2.0')
    expect(result.movies_count).toBe(150)

    // Test failed connection
    mockPost.mockRejectedValueOnce({
      response: {
        data: {
          error: {
            message: 'Connection refused',
          },
        },
      },
    })

    const failResult = await settingsStore.testRadarrConnection('radarr-1')

    expect(failResult.success).toBe(false)
    expect(failResult.error).toBe('Connection refused')
  })
})
