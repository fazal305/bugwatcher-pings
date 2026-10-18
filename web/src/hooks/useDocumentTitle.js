import { useEffect } from 'react'

const SITE = 'BugWatcher Pings'

export function useDocumentTitle(title) {
  useEffect(() => {
    document.title = title ? `${title} · ${SITE}` : `${SITE} — real-time error monitoring and alerts`
  }, [title])
}
