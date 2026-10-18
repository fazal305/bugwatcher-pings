export const API_URL = (import.meta.env.VITE_API_URL || 'http://127.0.0.1:8473/api').replace(/\/+$/, '')

const TOKEN_KEY = 'bwp_token'

export const SESSION_EXPIRED_MESSAGE = 'Your session expired, please sign in again.'

function readStoredToken() {
  try {
    return localStorage.getItem(TOKEN_KEY)
  } catch {
    return null
  }
}

let token = readStoredToken()

export function getToken() {
  return token
}

export function setToken(next) {
  token = next || null
  try {
    if (token) localStorage.setItem(TOKEN_KEY, token)
    else localStorage.removeItem(TOKEN_KEY)
  } catch {
    // Storage is blocked: the session still works until the tab is closed.
  }
}

export class ApiError extends Error {
  constructor(status, message, errors = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }
}

function messageFor(status, data) {
  if (status === 401) return SESSION_EXPIRED_MESSAGE
  if (status === 403) return data?.message || "You don't have permission to do that."
  if (status === 404) return data?.message && data.message !== 'Not Found.' ? data.message : "That page doesn't exist, or you don't have access to it."
  if (status === 422) return 'Please check the highlighted fields.'
  if (status === 429) return data?.message || 'Too many attempts. Please wait a minute and try again.'
  return 'Something went wrong on our side. Please try again.'
}

export async function api(path, { body, headers, ...options } = {}) {
  const sentToken = token
  let response
  try {
    response = await fetch(`${API_URL}${path}`, {
      ...options,
      headers: {
        Accept: 'application/json',
        ...(body !== undefined && { 'Content-Type': 'application/json' }),
        ...(sentToken && { Authorization: `Bearer ${sentToken}` }),
        ...headers,
      },
      body: body !== undefined ? JSON.stringify(body) : undefined,
    })
  } catch {
    throw new ApiError(
      0,
      navigator.onLine === false
        ? "You're offline. Check your connection and try again."
        : "We couldn't reach the server. Please try again in a moment.",
    )
  }

  const data = response.status === 204 ? null : await response.json().catch(() => null)
  if (!response.ok) {
    if (response.status === 401 && sentToken) {
      setToken(null)
      window.dispatchEvent(new CustomEvent('session-expired'))
    }
    throw new ApiError(response.status, messageFor(response.status, data), data?.errors || {})
  }
  return data
}

/** Laravel sends a list of messages per field; forms show the first. */
export function fieldErrors(error) {
  return Object.fromEntries(Object.entries(error?.errors || {}).map(([field, messages]) => [field, messages[0]]))
}
