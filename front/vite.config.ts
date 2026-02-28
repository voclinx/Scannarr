import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
    },
  },
  server: {
    port: 5173,
    host: '0.0.0.0',
    proxy: {
      '/api': {
        target: 'http://api:8080',
        changeOrigin: true,
      },
      '/ws': {
        target: 'ws://api:8081',
        ws: true,
        changeOrigin: true,
      },
    },
  },
})
