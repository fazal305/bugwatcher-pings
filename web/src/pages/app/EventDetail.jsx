import { useEffect, useLayoutEffect, useState } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router'
import { EnvironmentBadge, EventTags, SeverityBadge } from '../../components/feed'
import { Alert, Button, Card, ErrorState, Skeleton } from '../../components/ui'
import { useDocumentTitle } from '../../hooks/useDocumentTitle'
import { api } from '../../lib/api'
import { fullTime } from '../../lib/time'

export default function EventDetail() {
  const { slug, id } = useParams()
  const location = useLocation()
  const navigate = useNavigate()
  const [state, setState] = useState({ loading: true, error: null, event: null })
  const [attempt, setAttempt] = useState(0)
  useDocumentTitle(state.event ? state.event.message.slice(0, 60) : 'Event')

  const feedPath = `/app/projects/${encodeURIComponent(slug)}/feed`
  const path = `/projects/${encodeURIComponent(slug)}/events/${encodeURIComponent(id)}`

  useLayoutEffect(() => {
    window.scrollTo(0, 0)
  }, [id])

  useEffect(() => {
    let active = true
    api(path)
      .then((data) => active && setState({ loading: false, error: null, event: data.data }))
      .catch((err) => active && setState({ loading: false, error: err.message, event: null }))
    return () => {
      active = false
    }
  }, [path, attempt])

  function retry() {
    setState({ loading: true, error: null, event: null })
    setAttempt((n) => n + 1)
  }

  // Going back through history, rather than to a new feed entry, is what lets the feed keep its scroll position.
  const back = location.state?.fromFeed ? (
    <button type="button" onClick={() => navigate(-1)} className="text-sm font-semibold text-accent underline-offset-4 hover:underline">
      ← Back to the live feed
    </button>
  ) : (
    <Link to={feedPath} className="text-sm font-semibold text-accent underline-offset-4 hover:underline">
      ← Live feed
    </Link>
  )

  if (state.loading) {
    return (
      <div aria-busy="true" className="space-y-4">
        <span className="sr-only" role="status">
          Loading event…
        </span>
        <Skeleton className="h-8 w-40" />
        <Skeleton className="h-32" />
        <Skeleton className="h-64" />
      </div>
    )
  }
  if (state.error) {
    return (
      <>
        {back}
        <div className="mt-6">
          <ErrorState title="Couldn't load this event" message={state.error} onRetry={retry} />
        </div>
      </>
    )
  }

  const { event } = state
  return (
    <>
      {back}
      <div className="mt-4 flex flex-wrap items-center gap-1.5">
        <SeverityBadge level={event.level} />
        <EnvironmentBadge environment={event.environment} />
        <EventTags event={event} />
      </div>
      <h1 className="mt-3 text-2xl whitespace-pre-wrap [overflow-wrap:anywhere] sm:text-3xl">{event.message}</h1>

      <dl className="mt-5 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[9rem_minmax(0,1fr)]">
        <Meta label="Occurred">
          <time dateTime={event.occurred_at} title={event.occurred_at} className="font-mono">
            {fullTime(event.occurred_at)}
          </time>
        </Meta>
        <Meta label="Received">
          <time dateTime={event.received_at} title={event.received_at} className="font-mono">
            {fullTime(event.received_at)}
          </time>
        </Meta>
        {event.exception_type && <Meta label="Exception">{event.exception_type}</Meta>}
        {event.file && (
          <Meta label="Location">
            <code className="font-mono text-xs">
              {event.file}
              {event.line != null && `:${event.line}`}
            </code>
          </Meta>
        )}
        {event.release && <Meta label="Release">{event.release}</Meta>}
        {event.sdk && <Meta label="SDK">{event.sdk}</Meta>}
        <Meta label="Event ID">
          <code className="font-mono text-xs">{event.id}</code>
        </Meta>
      </dl>

      <div className="mt-8 space-y-6">
        <IssueCard slug={slug} issue={event.issue} onChange={(issue) => setState((s) => ({ ...s, event: { ...s.event, issue: { ...s.event.issue, ...issue } } }))} />
        <StackTrace frames={event.stacktrace} />
        <RequestCard request={event.request} />
        {event.user_context && (
          <Card title="User">
            <JsonBlock value={event.user_context} />
          </Card>
        )}
      </div>
    </>
  )
}

function Meta({ label, children }) {
  return (
    <>
      <dt className="text-muted">{label}</dt>
      <dd className="min-w-0 [overflow-wrap:anywhere]">{children}</dd>
    </>
  )
}

function IssueCard({ slug, issue, onChange }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const resolved = issue.status === 'resolved'

  async function toggle() {
    setBusy(true)
    setError(null)
    try {
      const { data } = await api(`/projects/${encodeURIComponent(slug)}/issues/${issue.id}`, { method: 'PATCH', body: { status: resolved ? 'open' : 'resolved' } })
      onChange(data)
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card title="Issue">
      <p className="mt-2 font-medium [overflow-wrap:anywhere]">{issue.title}</p>
      {issue.culprit && <code className="mt-1 block font-mono text-xs text-muted [overflow-wrap:anywhere]">{issue.culprit}</code>}
      <dl className="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[9rem_minmax(0,1fr)]">
        <Meta label="Status">{resolved ? 'Resolved' : 'Open'}</Meta>
        <Meta label="Events">{issue.event_count.toLocaleString()}</Meta>
        <Meta label="First seen">
          <time dateTime={issue.first_seen_at} title={issue.first_seen_at} className="font-mono">
            {fullTime(issue.first_seen_at)}
          </time>
        </Meta>
        <Meta label="Last seen">
          <time dateTime={issue.last_seen_at} title={issue.last_seen_at} className="font-mono">
            {fullTime(issue.last_seen_at)}
          </time>
        </Meta>
      </dl>
      {error && (
        <Alert tone="error" className="mt-3">
          {error}
        </Alert>
      )}
      <Button variant="secondary" className="mt-4" busy={busy} onClick={toggle}>
        {resolved ? 'Reopen issue' : 'Mark as resolved'}
      </Button>
    </Card>
  )
}

function StackTrace({ frames }) {
  if (!frames.length) return null
  return (
    <Card title="Stack trace" description="Innermost frame first.">
      <ol className="mt-4 space-y-3">
        {frames.map((frame, index) => (
          <li key={index} className="min-w-0 rounded-lg border border-line">
            <p className="flex flex-wrap items-baseline gap-x-2 border-b border-line bg-sunken px-3 py-2 font-mono text-xs [overflow-wrap:anywhere]">
              <span className="font-semibold">{frame.function || '(anonymous)'}</span>
              <span className="text-muted">
                {frame.file}
                {frame.line != null && `:${frame.line}`}
              </span>
              {frame.in_app === false && <span className="text-muted">(library)</span>}
            </p>
            {frame.context_line != null && <ContextLines frame={frame} />}
          </li>
        ))}
      </ol>
    </Card>
  )
}

function ContextLines({ frame }) {
  const first = frame.line != null ? frame.line - frame.pre_context.length : null
  const lines = [
    ...frame.pre_context.map((text) => ({ text, current: false })),
    { text: frame.context_line, current: true },
    ...frame.post_context.map((text) => ({ text, current: false })),
  ]
  return (
    <pre className="overflow-x-auto py-2 font-mono text-xs leading-relaxed">
      {lines.map((line, i) => (
        <div key={i} className={`flex min-w-max px-3 ${line.current ? 'bg-error-tint text-error' : ''}`} aria-current={line.current ? 'true' : undefined}>
          <span aria-hidden="true" className="w-12 shrink-0 select-none pr-3 text-right text-muted">
            {first != null ? first + i : ''}
          </span>
          <code>{line.text || ' '}</code>
        </div>
      ))}
    </pre>
  )
}

function RequestCard({ request }) {
  if (!request) return null
  const headers = request.headers && typeof request.headers === 'object' ? Object.entries(request.headers) : []
  return (
    <Card title="Request" description="Sensitive values were replaced with [Filtered] before the report was stored.">
      {(request.method || request.url) && (
        <p className="mt-3 font-mono text-sm [overflow-wrap:anywhere]">
          {request.method && <span className="font-semibold">{request.method} </span>}
          {request.url}
        </p>
      )}
      {headers.length > 0 && (
        <>
          <h3 className="mt-4 text-base">Headers</h3>
          <dl className="mt-2 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[12rem_minmax(0,1fr)]">
            {headers.map(([name, value]) => (
              <Meta key={name} label={name}>
                <code className="font-mono text-xs">{typeof value === 'string' ? value : JSON.stringify(value)}</code>
              </Meta>
            ))}
          </dl>
        </>
      )}
      {request.query != null && (
        <>
          <h3 className="mt-4 text-base">Query</h3>
          <JsonBlock value={request.query} />
        </>
      )}
      {request.body != null && (
        <>
          <h3 className="mt-4 text-base">Body</h3>
          <JsonBlock value={request.body} />
        </>
      )}
    </Card>
  )
}

function JsonBlock({ value }) {
  return <pre className="mt-2 overflow-x-auto rounded-lg bg-sunken p-3 font-mono text-xs leading-relaxed">{typeof value === 'string' ? value : JSON.stringify(value, null, 2)}</pre>
}
