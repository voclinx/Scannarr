import { createRouter, createWebHistory } from 'vue-router'
import type { RouteRecordRaw } from 'vue-router'
import type { UserRole } from '@/types'

const LoginView = () => import('@/views/LoginView.vue')
const SetupWizardView = () => import('@/views/SetupWizardView.vue')
const AppLayout = () => import('@/components/layout/AppLayout.vue')
const DashboardView = () => import('@/views/DashboardView.vue')
const FileExplorerView = () => import('@/views/FileExplorerView.vue')
const MoviesListView = () => import('@/views/MoviesListView.vue')
const MovieDetailView = () => import('@/views/MovieDetailView.vue')
const ScheduledDeletionsView = () => import('@/views/ScheduledDeletionsView.vue')
const SettingsView = () => import('@/views/SettingsView.vue')
const UsersManagementView = () => import('@/views/UsersManagementView.vue')
const NotFoundView = () => import('@/views/NotFoundView.vue')

declare module 'vue-router' {
  interface RouteMeta {
    guest?: boolean
    requiresAuth?: boolean
    minRole?: UserRole
  }
}

const routes: RouteRecordRaw[] = [
  { path: '/login', name: 'login', component: LoginView, meta: { guest: true } },
  { path: '/setup', name: 'setup', component: SetupWizardView, meta: { guest: true } },
  {
    path: '/',
    component: AppLayout,
    meta: { requiresAuth: true },
    children: [
      { path: '', name: 'dashboard', component: DashboardView, meta: { minRole: 'ROLE_GUEST' } },
      { path: 'files', name: 'files', component: FileExplorerView, meta: { minRole: 'ROLE_GUEST' } },
      { path: 'movies', name: 'movies', component: MoviesListView, meta: { minRole: 'ROLE_GUEST' } },
      { path: 'movies/:id', name: 'movie-detail', component: MovieDetailView, meta: { minRole: 'ROLE_GUEST' } },
      { path: 'deletions', name: 'deletions', component: ScheduledDeletionsView, meta: { minRole: 'ROLE_USER' } },
      { path: 'settings', name: 'settings', component: SettingsView, meta: { minRole: 'ROLE_ADMIN' } },
      { path: 'users', name: 'users', component: UsersManagementView, meta: { minRole: 'ROLE_ADMIN' } },
    ],
  },
  { path: '/:pathMatch(.*)*', name: 'not-found', component: NotFoundView },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to, _from, next) => {
  // Dynamic import to avoid circular dependency
  const { useAuthStore } = await import('@/stores/auth')
  const authStore = useAuthStore()

  // If we have a token but no user loaded, try fetching the user
  if (authStore.accessToken && !authStore.user) {
    try {
      await authStore.fetchMe()
    } catch {
      authStore.clearAuth()
    }
  }

  const requiresAuth = to.matched.some((record) => record.meta.requiresAuth)
  const isGuestRoute = to.matched.some((record) => record.meta.guest)

  // Guest routes (login, setup): redirect to dashboard if already authenticated
  if (isGuestRoute && authStore.isAuthenticated) {
    return next({ name: 'dashboard' })
  }

  // Auth required: redirect to login if not authenticated
  if (requiresAuth && !authStore.isAuthenticated) {
    return next({ name: 'login', query: { redirect: to.fullPath } })
  }

  // Role check: verify minimum role for the target route
  const minRole = to.meta.minRole as UserRole | undefined
  if (minRole && authStore.isAuthenticated && !authStore.hasMinRole(minRole)) {
    return next({ name: 'dashboard' })
  }

  next()
})

export default router
