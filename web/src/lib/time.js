const pad = (n, width = 2) => String(n).padStart(width, '0')

/** Local wall-clock time to the millisecond, e.g. 14:02:07.183. */
export function clockTime(iso) {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  return `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}.${pad(d.getMilliseconds(), 3)}`
}

/** Local date and time to the millisecond, e.g. 2026-10-18 14:02:07.183. */
export function fullTime(iso) {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${clockTime(iso)}`
}

export function relativeTime(iso, now = Date.now()) {
  const seconds = Math.round((now - new Date(iso).getTime()) / 1000)
  if (!Number.isFinite(seconds)) return ''
  if (seconds < 5) return 'just now'
  if (seconds < 60) return `${seconds}s ago`
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`
  return `${Math.floor(seconds / 86400)}d ago`
}
