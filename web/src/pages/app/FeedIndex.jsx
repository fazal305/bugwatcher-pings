import { useEffect, useState } from 'react'
import { Navigate } from 'react-router'
import { ErrorState, LinkButton, Skeleton } from '../../components/ui'
import { useDocumentTitle } from '../../hooks/useDocumentTitle'
import { api } from '../../lib/api'
import { LAST_FEED_KEY } from './Feed'

function lastFeed() {
  try {
    return localStorage.getItem(LAST_FEED_KEY)
  } catch {
    return null
  }
}

/** Opens the feed of the project used last, or the first one. */
export default function FeedIndex() {
  useDocumentTitle('Live feed')
  const [state, setState] = useState({ loading: true, error: null, projects: [] })
  const [attempt, setAttempt] = useState(0)

  useEffect(() => {
    let active = true
    api('/projects')
      .then((data) => active && setState({ loading: false, error: null, projects: data.data }))
      .catch((err) => active && setState({ loading: false, error: err.message, projects: [] }))
    return () => {
      active = false
    }
  }, [attempt])

  if (state.loading) {
    return (
      <div aria-busy="true" className="space-y-3">
        <span className="sr-only" role="status">
          Loading your projects…
        </span>
        <Skeleton className="h-10 w-48" />
        <Skeleton className="h-28" />
        <Skeleton className="h-28" />
      </div>
    )
  }
  if (state.error) {
    return (
      <ErrorState
        title="Couldn't load your projects"
        message={state.error}
        onRetry={() => {
          setState({ loading: true, error: null, projects: [] })
          setAttempt((n) => n + 1)
        }}
      />
    )
  }
  if (state.projects.length === 0) {
    return (
      <>
        <h1 className="text-3xl">Live feed</h1>
        <div className="mt-8 rounded-card border border-dashed border-line-strong bg-surface px-6 py-12 text-center">
          <h2 className="text-lg">No projects yet</h2>
          <p className="mx-auto mt-1 max-w-md text-sm text-muted">Create a project to get a key for your app. Its errors will appear here as they happen.</p>
          <LinkButton to="/app/projects/new" className="mt-5">
            Create a project
          </LinkButton>
        </div>
      </>
    )
  }

  const remembered = lastFeed()
  const slug = state.projects.some((p) => p.slug === remembered) ? remembered : state.projects[0].slug
  return <Navigate to={`/app/projects/${encodeURIComponent(slug)}/feed`} replace />
}
