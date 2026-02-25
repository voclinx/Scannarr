import axios from 'axios'
import type { AxiosInstance } from 'axios'
import { useAuthStore } from '@/stores/auth'
import router from '@/router'

let apiInstance: AxiosInstance | null = null

export function useApi(): AxiosInstance {
  if (apiInstance) return apiInstance

  apiInstance = axios.create({
    baseURL: '/api/v1',
    headers: {
      'Content-Type': 'application/json',
    },
  })

  apiInstance.interceptors.request.use((config) => {
    const authStore = useAuthStore()
    if (authStore.accessToken) {
      config.headers.Authorization = `Bearer ${authStore.accessToken}`
    }
    return config
  })

  let isRefreshing = false
  let failedQueue: Array<{
    resolve: (token: string) => void
    reject: (error: unknown) => void
  }> = []

  function processQueue(error: unknown, token: string | null = null) {
    failedQueue.forEach((prom) => {
      if (token) {
        prom.resolve(token)
      } else {
        prom.reject(error)
      }
    })
    failedQueue = []
  }

  apiInstance.interceptors.response.use(
    (response) => response,
    async (error) => {
      const originalRequest = error.config

      if (error.response?.status !== 401 || originalRequest._retry) {
        return Promise.reject(error)
      }

      const authStore = useAuthStore()

      if (!authStore.refreshToken) {
        authStore.clearAuth()
        await router.push({ name: 'login' })
        return Promise.reject(error)
      }

      if (isRefreshing) {
        return new Promise((resolve, reject) => {
          failedQueue.push({
            resolve: (token: string) => {
              originalRequest.headers.Authorization = `Bearer ${token}`
              resolve(apiInstance!.request(originalRequest))
            },
            reject,
          })
        })
      }

      originalRequest._retry = true
      isRefreshing = true

      try {
        const response = await axios.post('/api/v1/auth/refresh', {
          refresh_token: authStore.refreshToken,
        })

        const { access_token, refresh_token } = response.data.data
        authStore.setTokens(access_token, refresh_token)
        processQueue(null, access_token)

        originalRequest.headers.Authorization = `Bearer ${access_token}`
        return apiInstance!.request(originalRequest)
      } catch (refreshError) {
        processQueue(refreshError, null)
        authStore.clearAuth()
        await router.push({ name: 'login' })
        return Promise.reject(refreshError)
      } finally {
        isRefreshing = false
      }
    },
  )

  return apiInstance
}
