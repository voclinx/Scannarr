import { onUnmounted, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'

export type WatcherSocketEvent =
  | { type: 'watcher.status_changed'; data: { id: string; watcher_id: string; status: string; last_seen_at?: string } }
  | { type: 'browser.ready'; data: { connected_watcher_ids: string[] } }

type EventHandler = (event: WatcherSocketEvent) => void

/**
 * Composable that maintains a WebSocket connection to /ws/events for real-time watcher status.
 *
 * The connection is proxied through Vite (dev) or Nginx (prod):
 *   ws://<same-origin>/ws/events  →  ws://api:8081/ws/events
 */
export function useWatcherSocket(onEvent?: EventHandler) {
  const auth = useAuthStore()
  const connected = ref(false)
  let ws: WebSocket | null = null
  let reconnectTimer: ReturnType<typeof setTimeout> | null = null
  let destroyed = false

  function buildWsUrl(): string {
    const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:'
    return `${protocol}//${window.location.host}/ws/events`
  }

  function connect() {
    if (destroyed) return

    const token = auth.accessToken
    if (!token) return

    try {
      ws = new WebSocket(buildWsUrl())
    } catch {
      scheduleReconnect()
      return
    }

    ws.onopen = () => {
      connected.value = true
      // Authenticate with JWT token
      ws?.send(JSON.stringify({ type: 'browser.auth', data: { token } }))
    }

    ws.onmessage = (event) => {
      try {
        const msg = JSON.parse(event.data as string) as WatcherSocketEvent
        onEvent?.(msg)
      } catch {
        // Ignore malformed messages
      }
    }

    ws.onclose = () => {
      connected.value = false
      ws = null
      scheduleReconnect()
    }

    ws.onerror = () => {
      // onclose will be called right after, which handles reconnect
    }
  }

  function scheduleReconnect() {
    if (destroyed) return
    if (reconnectTimer) clearTimeout(reconnectTimer)
    reconnectTimer = setTimeout(connect, 5000)
  }

  function disconnect() {
    destroyed = true
    if (reconnectTimer) clearTimeout(reconnectTimer)
    if (ws) {
      ws.onclose = null // Prevent reconnect loop
      ws.close()
      ws = null
    }
    connected.value = false
  }

  // Auto-connect and auto-disconnect
  connect()
  onUnmounted(disconnect)

  return { connected, disconnect }
}
