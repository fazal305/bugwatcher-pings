const ENTITIES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }

/**
 * For the rare string that leaves React's escaping (e.g. a document.title or a
 * notification body built from error data). JSX text never needs this.
 */
export function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (ch) => ENTITIES[ch])
}
