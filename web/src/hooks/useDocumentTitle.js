import { useEffect } from 'react'

const SITE = 'BugWatcher Pings'
const SITE_URL = 'https://bugwatcher-pings.vercel.app'

export function useDocumentTitle(title) {
  useEffect(() => {
    document.title = title ? `${title} · ${SITE}` : `${SITE} — real-time error monitoring and alerts`
    // index.html points every route at the home page; each public page is its own canonical URL.
    document.querySelector('link[rel="canonical"]')?.setAttribute('href', `${SITE_URL}${window.location.pathname}`)
  }, [title])
}
