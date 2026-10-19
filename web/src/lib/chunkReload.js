// After a deploy, a tab opened earlier still asks for the old hashed chunks, which no longer exist. Reloading once picks up
// the new build. The timestamp in sessionStorage stops a reload loop when the chunk is missing for some other reason.
const RELOADED_AT_KEY = 'bwp_chunk_reload_at'
const RELOAD_WINDOW_MS = 10_000

let reloading = false

/** True for the errors Chrome, Safari and Firefox throw when a dynamically imported chunk can't be loaded. */
export function isChunkLoadError(error) {
  const message = String(error?.message ?? error ?? '')
  return /Failed to fetch dynamically imported module|Importing a module script failed|error loading dynamically imported module|Unable to preload CSS/i.test(message)
}

export function isReloading() {
  return reloading
}

/** Reloads the page, unless it already reloaded for this in the last few seconds. Returns whether a reload is under way. */
export function reloadOnce() {
  if (reloading) return true
  try {
    const last = Number(sessionStorage.getItem(RELOADED_AT_KEY)) || 0
    if (Date.now() - last < RELOAD_WINDOW_MS) return false
    sessionStorage.setItem(RELOADED_AT_KEY, String(Date.now()))
  } catch {
    // Without storage there is no guard against a loop, so let the error show instead.
    return false
  }
  reloading = true
  window.location.reload()
  return true
}
