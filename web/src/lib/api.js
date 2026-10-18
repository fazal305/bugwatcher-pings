export const API_URL = (import.meta.env.VITE_API_URL || 'http://127.0.0.1:8473/api').replace(/\/+$/, '')

export class ApiError extends Error {
  constructor(status, message) {
    super(message)
    this.status = status
  }
}

export async function api(path, { body, headers, ...options } = {}) {
  const response = await fetch(`${API_URL}${path}`, {
    ...options,
    headers: {
      Accept: 'application/json',
      ...(body !== undefined && { 'Content-Type': 'application/json' }),
      ...headers,
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  })
  const data = response.status === 204 ? null : await response.json().catch(() => null)
  if (!response.ok) throw new ApiError(response.status, data?.message || 'Something went wrong. Please try again.')
  return data
}
