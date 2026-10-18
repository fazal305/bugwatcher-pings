import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import { ErrorState, LinkButton, Skeleton } from '../../components/ui'
import { useDocumentTitle } from '../../hooks/useDocumentTitle'
import { useOnline } from '../../hooks/useOnline'
import { api } from '../../lib/api'

const ROLE_LABEL = { admin: 'Admin', developer: 'Developer' }

export default function Projects() {
  useDocumentTitle('Projects')
  const online = useOnline()
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

  function retry() {
    setState((s) => ({ ...s, loading: true, error: null }))
    setAttempt((n) => n + 1)
  }

  return (
    <>
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-3xl">Projects</h1>
          <p className="mt-2 text-muted">Each project has its own key for sending error reports.</p>
        </div>
        <LinkButton to="/app/projects/new">New project</LinkButton>
      </div>

      <div className="mt-8">
        {!online && !state.loading && (
          <p role="status" className="mb-4 rounded-lg border border-line-strong bg-surface px-4 py-3 text-sm">
            You&apos;re offline. This list may be out of date until your connection is back.
          </p>
        )}
        {state.loading ? (
          <div aria-busy="true" className="space-y-3">
            <span className="sr-only" role="status">
              Loading projects…
            </span>
            <Skeleton className="h-20" />
            <Skeleton className="h-20" />
            <Skeleton className="h-20" />
          </div>
        ) : state.error ? (
          <ErrorState title="Couldn't load your projects" message={state.error} onRetry={retry} />
        ) : state.projects.length === 0 ? (
          <div className="rounded-card border border-dashed border-line-strong bg-surface px-6 py-12 text-center">
            <h2 className="text-lg">No projects yet</h2>
            <p className="mx-auto mt-1 max-w-md text-sm text-muted">Create a project to get a public key for your app, then invite your team.</p>
            <LinkButton to="/app/projects/new" className="mt-5">
              Create your first project
            </LinkButton>
          </div>
        ) : (
          <ul className="space-y-3">
            {state.projects.map((project) => (
              <li key={project.slug}>
                <Link
                  to={`/app/projects/${encodeURIComponent(project.slug)}`}
                  className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 rounded-card border border-line bg-surface px-5 py-4 hover:border-line-strong"
                >
                  <span className="min-w-0">
                    <span className="block truncate font-display text-lg font-semibold">{project.name}</span>
                    <span className="block font-mono text-xs text-muted">{project.slug}</span>
                  </span>
                  <span className="rounded-full bg-sunken px-2.5 py-0.5 text-xs font-medium text-muted">{ROLE_LABEL[project.role]}</span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </div>
    </>
  )
}
