import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { API_URL, api } from './api'

const env = import.meta.env
const DRIVER = env.VITE_BROADCAST_DRIVER === 'pusher' ? 'pusher' : 'reverb'
const REFRESH_MARGIN_MS = 60_000

let echo = null
let jwt = null
let pendingToken = null
let refreshTimer = null
let needsFreshToken = false
let state = 'initialized'
let everConnected = false
const listeners = new Set()

function scheduleRefresh() {
  clearTimeout(refreshTimer)
  const wait = Math.max(jwt.expiresAt - Date.now() - REFRESH_MARGIN_MS, 5_000)
  refreshTimer = setTimeout(() => {
    if (echo) realtimeToken({ force: true }).catch(() => {})
  }, wait)
}

async function fetchToken() {
  const data = await api('/realtime/token', { method: 'POST' })
  jwt = { token: data.token, expiresAt: Date.now() + data.expires_in * 1000, projects: data.projects }
  scheduleRefresh()
  return jwt
}

/** A short-lived JWT for channel authorization, renewed ahead of expiry or when it doesn't cover the project yet. */
export function realtimeToken({ force = false, slug } = {}) {
  const usable = jwt && jwt.expiresAt - Date.now() > REFRESH_MARGIN_MS && (!slug || jwt.projects.includes(slug))
  if (usable && !force) return Promise.resolve(jwt.token)
  pendingToken ??= fetchToken().finally(() => {
    pendingToken = null
  })
  return pendingToken.then((t) => t.token)
}

async function requestSignature(token, socketId, channelName) {
  return fetch(`${API_URL}/broadcasting/auth`, {
    method: 'POST',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
    body: JSON.stringify({ socket_id: socketId, channel_name: channelName }),
  })
}

// The generic api() wrapper would treat a 401 here as an expired sign-in; for this endpoint it only means the JWT needs renewing.
async function authorizeChannel({ socketId, channelName }, callback) {
  const slug = channelName.replace(/^private-project\./, '')
  try {
    const force = needsFreshToken
    needsFreshToken = false
    let response = await requestSignature(await realtimeToken({ force, slug }), socketId, channelName)
    if (response.status === 401) {
      response = await requestSignature(await realtimeToken({ force: true, slug }), socketId, channelName)
    }
    if (!response.ok) throw new Error(`Channel authorization failed (${response.status})`)
    callback(null, await response.json())
  } catch (error) {
    callback(error instanceof Error ? error : new Error('Channel authorization failed'), null)
  }
}

function setState(next) {
  state = next
  listeners.forEach((listener) => listener())
}

function createClient() {
  const common = {
    disableStats: true,
    channelAuthorization: { customHandler: authorizeChannel },
  }
  if (DRIVER === 'pusher') {
    return new Pusher(env.VITE_PUSHER_KEY, { ...common, cluster: env.VITE_PUSHER_CLUSTER || 'mt1', forceTLS: true })
  }
  const tls = (env.VITE_REVERB_SCHEME || 'https') === 'https'
  const port = Number(env.VITE_REVERB_PORT) || (tls ? 443 : 80)
  return new Pusher(env.VITE_REVERB_APP_KEY, {
    ...common,
    cluster: '',
    wsHost: env.VITE_REVERB_HOST,
    wsPort: port,
    wssPort: port,
    forceTLS: tls,
    enabledTransports: ['ws', 'wss'],
  })
}

export function getEcho() {
  if (echo) return echo
  const client = createClient()
  client.connection.bind('state_change', ({ current }) => {
    if (current === 'connected') everConnected = true
    // Any drop means the next subscription goes out with a newly issued token.
    else if (everConnected) needsFreshToken = true
    setState(current)
  })
  echo = new Echo({ broadcaster: DRIVER, client, withoutInterceptors: true })
  setState(client.connection.state)
  return echo
}

export function subscribeConnection(listener) {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

/** "connecting" | "live" | "reconnecting" | "offline" */
export function connectionStatus() {
  if (state === 'connected') return 'live'
  if (state === 'failed' || state === 'disconnected') return 'offline'
  return everConnected || state === 'unavailable' ? 'reconnecting' : 'connecting'
}

export function resetRealtime() {
  clearTimeout(refreshTimer)
  echo?.disconnect()
  echo = null
  jwt = null
  pendingToken = null
  everConnected = false
  needsFreshToken = false
  setState('initialized')
}
