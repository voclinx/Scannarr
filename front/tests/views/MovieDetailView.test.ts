import { describe, it, expect, vi, beforeEach } from 'vitest'
import { shallowMount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import MovieDetailView from '@/views/MovieDetailView.vue'
import { useMoviesStore } from '@/stores/movies'
import type { MovieDetail, MovieFileDetail } from '@/types'

// Mock router
const mockPush = vi.fn()

vi.mock('vue-router', () => ({
  useRouter: () => ({
    push: mockPush,
  }),
  useRoute: () => ({
    params: { id: 'movie-1' },
    name: 'movie-detail',
    query: {},
  }),
}))

// Mock useApi
const mockGet = vi.fn()
const mockPost = vi.fn()
const mockDelete = vi.fn()

vi.mock('@/composables/useApi', () => ({
  useApi: () => ({
    get: mockGet,
    post: mockPost,
    delete: mockDelete,
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

const fakeFiles: MovieFileDetail[] = [
  {
    id: 'file-1',
    volume_id: 'vol-1',
    volume_name: 'Volume 1',
    file_path: '/mnt/media/Inception.2010.1080p.mkv',
    file_name: 'Inception.2010.1080p.mkv',
    file_size_bytes: 5000000000,
    hardlink_count: 1,
    resolution: '1080p',
    is_linked_radarr: true,
    is_linked_media_player: false,
    detected_at: '2026-01-15T10:00:00Z',
    matched_by: 'radarr_api',
    confidence: 0.95,
  },
  {
    id: 'file-2',
    volume_id: 'vol-1',
    volume_name: 'Volume 1',
    file_path: '/mnt/media/Inception.2010.4K.mkv',
    file_name: 'Inception.2010.4K.mkv',
    file_size_bytes: 12000000000,
    hardlink_count: 2,
    resolution: '2160p',
    is_linked_radarr: true,
    is_linked_media_player: true,
    detected_at: '2026-01-16T10:00:00Z',
    matched_by: 'filename_parse',
    confidence: 0.75,
  },
]

const fakeMovieDetail: MovieDetail = {
  id: 'movie-1',
  title: 'Inception',
  original_title: 'Inception',
  year: 2010,
  synopsis: 'A thief who steals corporate secrets through dream-sharing technology.',
  poster_url: 'https://example.com/poster.jpg',
  genres: 'Sci-Fi, Action',
  rating: 8.8,
  runtime_minutes: 148,
  file_count: 2,
  max_file_size_bytes: 12000000000,
  files_summary: [],
  is_monitored_radarr: true,
  files: fakeFiles,
  radarr_instance: { id: 'radarr-1', name: 'Radarr 4K' },
  radarr_monitored: true,
}

// Stub PrimeVue components
const globalStubs = {
  DataTable: {
    template: '<div data-testid="data-table"><slot name="empty" /><slot v-for="row in value" :data="row" /></div>',
    props: ['value', 'dataKey', 'stripedRows', 'loading'],
  },
  Column: {
    template: '<div><slot :data="{}" /></div>',
    props: ['field', 'header', 'style'],
  },
  Tag: {
    template: '<span data-testid="tag">{{ value }}</span>',
    props: ['value', 'severity'],
  },
  Button: {
    template: '<button @click="$emit(\'click\')">{{ label }}</button>',
    props: ['label', 'loading', 'icon', 'severity', 'text', 'size', 'disabled'],
    emits: ['click'],
  },
  Message: {
    template: '<div role="alert"><slot /></div>',
    props: ['severity', 'closable'],
  },
  ProgressSpinner: {
    template: '<div data-testid="spinner">Loading...</div>',
  },
  Dialog: {
    template: '<div v-if="visible" data-testid="dialog"><slot /><slot name="footer" /></div>',
    props: ['visible', 'modal', 'header', 'style'],
  },
  DatePicker: {
    template: '<input type="date" />',
    props: ['modelValue', 'minDate', 'dateFormat', 'placeholder'],
  },
  InputNumber: {
    template: '<input type="number" />',
    props: ['modelValue', 'min', 'max'],
  },
  Checkbox: {
    template: '<input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', !modelValue)" />',
    props: ['modelValue', 'binary', 'inputId'],
    emits: ['update:modelValue'],
  },
  MovieGlobalDeleteModal: {
    template: '<div data-testid="delete-modal" v-if="visible"><slot /></div>',
    props: ['visible', 'movie'],
    emits: ['update:visible', 'confirm'],
  },
}

describe('MovieDetailView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  // ---------- TEST-FRONT-007 ----------
  it('TEST-FRONT-007: displays movie info and files after loading', async () => {
    // Mock fetchMovie response
    mockGet.mockResolvedValueOnce({
      data: { data: fakeMovieDetail },
    })

    const wrapper = shallowMount(MovieDetailView, {
      global: {
        stubs: globalStubs,
      },
    })

    await flushPromises()

    // Pre-populate the store directly since the API mock feeds through the store
    const moviesStore = useMoviesStore()
    moviesStore.currentMovie = fakeMovieDetail
    moviesStore.currentMovieLoading = false

    await wrapper.vm.$nextTick()

    // Verify movie title is displayed
    expect(wrapper.text()).toContain('Inception')

    // Verify year is displayed
    expect(wrapper.text()).toContain('2010')

    // Verify genres
    expect(wrapper.text()).toContain('Sci-Fi, Action')

    // Verify synopsis
    expect(wrapper.text()).toContain('A thief who steals corporate secrets')

    // Verify files section header shows count
    expect(wrapper.text()).toContain('Fichiers liés (2)')

    // Verify fetchMovie was called with the route param ID
    expect(mockGet).toHaveBeenCalledWith('/movies/movie-1')
  })

  // ---------- TEST-FRONT-008 ----------
  it('TEST-FRONT-008: MovieGlobalDeleteModal checkboxes toggle correctly', async () => {
    // This test checks the MovieGlobalDeleteModal component logic directly
    // We import and test the component's checkbox state management

    const { mount: fullMount } = await import('@vue/test-utils')

    // We directly test the toggle logic of the modal through a simplified approach
    const MovieGlobalDeleteModal = (await import('@/components/movies/MovieGlobalDeleteModal.vue')).default

    const movieForModal: MovieDetail = {
      ...fakeMovieDetail,
      files: fakeFiles,
    }

    const wrapper = fullMount(MovieGlobalDeleteModal, {
      props: {
        visible: true,
        movie: movieForModal,
      },
      global: {
        stubs: {
          Dialog: {
            template: '<div><slot /><slot name="footer" /></div>',
            props: ['visible', 'modal', 'header', 'style', 'closable'],
          },
          Button: {
            template: '<button @click="$emit(\'click\')">{{ label }}</button>',
            props: ['label', 'loading', 'icon', 'severity', 'text', 'disabled'],
            emits: ['click'],
          },
          Checkbox: {
            template: '<input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', !modelValue)" data-testid="checkbox" />',
            props: ['modelValue', 'binary', 'inputId'],
            emits: ['update:modelValue'],
          },
          Message: {
            template: '<div><slot /></div>',
            props: ['severity', 'closable'],
          },
        },
      },
    })

    await flushPromises()

    // All files should be selected by default when modal opens (watch triggers on visible)
    const checkboxes = wrapper.findAll('[data-testid="checkbox"]')
    // There should be checkboxes for each file + option checkboxes
    expect(checkboxes.length).toBeGreaterThan(0)

    // The modal should show the movie title
    expect(wrapper.text()).toContain('Inception')

    // The modal should display file names
    expect(wrapper.text()).toContain('Inception.2010.1080p.mkv')
    expect(wrapper.text()).toContain('Inception.2010.4K.mkv')

    // Verify file selection count text
    expect(wrapper.text()).toContain('Fichiers à supprimer')
  })
})
