import { useEffect, useId, useLayoutEffect, useMemo, useState } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router'
import { EnvironmentBadge, EventTags, SeverityBadge } from '../../components/feed'
import { Alert, Button, Card, ErrorState, Skeleton } from '../../components/ui'
import { useDocumentTitle } from '../../hooks/useDocumentTitle'
import { api } from '../../lib/api'
import { highlightLines, languageFor, loadHighlighter } from '../../lib/highlight'
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
      <div className="flex flex-wrap items-center justify-between gap-3">
        {back}
        <CopyJsonButton event={event} />
      </div>
      <div className="mt-4 flex flex-wrap items-center gap-1.5">
        <SeverityBadge level={event.level} />
        <EnvironmentBadge environment={event.environment} />
        <EventTags event={event} />
      </div>
      <h1 className="mt-3 text-2xl whitespace-pre-wrap [overflow-wrap:anywhere] sm:text-3xl">{event.message}</h1>

      <dl className="mt-5 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[9rem_minmax(0,1fr)]">
        {event.exception_type && (
          <Meta label="Exception">
            <code className="font-mono text-xs">{event.exception_type}</code>
          </Meta>
        )}
        <Meta label="Occurred">
          <Time iso={event.occurred_at} />
        </Meta>
        <Meta label="Received">
          <Time iso={event.received_at} />
        </Meta>
        <Meta label="Processed">
          <Time iso={event.processed_at} />
        </Meta>
        {event.file && (
          <Meta label="Location">
            <code className="font-mono text-xs">
              {event.file}
              {event.line != null && `:${event.line}`}
            </code>
          </Meta>
        )}
        <Meta label="Release">{event.release || <span className="text-muted">Not set</span>}</Meta>
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
            <JsonBlock value={event.user_context} label="User context" />
          </Card>
        )}
      </div>
    </>
  )
}

function Time({ iso }) {
  return (
    <time dateTime={iso} title={iso} className="font-mono">
      {fullTime(iso)}
    </time>
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

function CopyJsonButton({ event }) {
  const [status, setStatus] = useState('')

  async function copy() {
    try {
      await navigator.clipboard.writeText(JSON.stringify(event, null, 2))
      setStatus('Copied the event as JSON.')
    } catch {
      setStatus("Couldn't copy automatically. Your browser blocked clipboard access.")
    }
  }

  return (
    <div className="flex flex-wrap items-center gap-3">
      <p role="status" className="text-sm text-muted">
        {status}
      </p>
      <Button variant="secondary" className="min-h-9 px-3" onClick={copy}>
        Copy as JSON
      </Button>
    </div>
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
          <Time iso={issue.first_seen_at} />
        </Meta>
        <Meta label="Last seen">
          <Time iso={issue.last_seen_at} />
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

// In-app frames start open; library frames start closed. Without any in-app frame, the thrown frame is opened.
function initiallyOpen(frames) {
  const open = new Set(frames.flatMap((frame, i) => (frame.in_app ? [i] : [])))
  if (open.size === 0 && frames.length) open.add(0)
  return open
}

function StackTrace({ frames }) {
  const [open, setOpen] = useState(() => initiallyOpen(frames))
  if (!frames.length) return null

  const allOpen = open.size === frames.length
  function toggle(index) {
    setOpen((current) => {
      const next = new Set(current)
      if (next.has(index)) next.delete(index)
      else next.add(index)
      return next
    })
  }

  return (
    <Card>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h2 className="text-lg">Stack trace</h2>
          <p className="mt-1 text-sm text-muted">
            {frames.length} {frames.length === 1 ? 'frame' : 'frames'}, innermost first.
          </p>
        </div>
        {frames.length > 1 && (
          <Button variant="ghost" className="min-h-9 px-3" onClick={() => setOpen(allOpen ? new Set() : new Set(frames.map((_, i) => i)))}>
            {allOpen ? 'Collapse all' : 'Expand all'}
          </Button>
        )}
      </div>
      <ol className="mt-4 space-y-2">
        {frames.map((frame, index) => (
          <Frame key={index} frame={frame} thrown={index === 0} open={open.has(index)} onToggle={() => toggle(index)} />
        ))}
      </ol>
    </Card>
  )
}

function Frame({ frame, thrown, open, onToggle }) {
  const bodyId = useId()
  const hasCode = frame.context_line != null
  return (
    <li className={`min-w-0 rounded-lg border ${thrown ? 'border-line-strong' : 'border-line'}`}>
      <button
        type="button"
        aria-expanded={open}
        aria-controls={bodyId}
        onClick={onToggle}
        className={`flex w-full min-w-0 items-start gap-2 rounded-lg px-3 py-2.5 text-left font-mono text-xs hover:bg-sunken ${open ? 'rounded-b-none bg-sunken' : ''}`}
      >
        <span aria-hidden="true" className={`mt-px inline-block w-3 shrink-0 text-muted transition-transform ${open ? 'rotate-90' : ''}`}>
          ▸
        </span>
        <span className="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-2 gap-y-1 [overflow-wrap:anywhere]">
          <span className="font-semibold">{frame.function || '(anonymous)'}</span>
          <span className="text-muted">
            {frame.file || '(unknown file)'}
            {frame.line != null && `:${frame.line}`}
          </span>
          {thrown && <span className="rounded-full bg-error-tint px-2 font-sans font-semibold text-error">Thrown here</span>}
          {frame.in_app === true && <span className="rounded-full bg-accent-tint px-2 font-sans font-medium text-accent-strong">Your code</span>}
          {frame.in_app === false && <span className="rounded-full border border-line-strong px-2 font-sans font-medium text-muted">Library</span>}
        </span>
      </button>
      <div id={bodyId} hidden={!open} className="min-w-0 border-t border-line">
        {open && (hasCode ? <CodeContext frame={frame} /> : <p className="px-3 py-2.5 text-sm text-muted">No source lines were sent for this frame.</p>)}
      </div>
    </li>
  )
}

function useHighlighter(language) {
  const [hljs, setHljs] = useState(null)
  useEffect(() => {
    if (!language) return
    let active = true
    loadHighlighter()
      .then((loaded) => active && setHljs(() => loaded))
      .catch(() => {
        // Without the highlighter the code still shows, as plain text.
      })
    return () => {
      active = false
    }
  }, [language])
  return language ? hljs : null
}

function CodeContext({ frame }) {
  const language = languageFor(frame.file)
  const hljs = useHighlighter(language)
  const lines = useMemo(() => [...frame.pre_context, frame.context_line, ...frame.post_context], [frame])
  const html = useMemo(() => (hljs ? highlightLines(hljs, lines, language) : null), [hljs, lines, language])
  const current = frame.pre_context.length
  const first = frame.line != null ? frame.line - current : null

  return (
    <div
      role="region"
      tabIndex={0}
      aria-label={`Source around ${frame.file || 'this frame'}${frame.line != null ? ` line ${frame.line}` : ''}`}
      className="min-w-0 overflow-x-auto rounded-b-lg bg-code focus-visible:outline-offset-[-2px]"
    >
      <pre className="min-w-max py-2 font-mono text-xs leading-relaxed text-ink" data-language={language || 'plain'}>
        {lines.map((text, i) => {
          const isCurrent = i === current
          return (
            <div key={i} className={`flex pr-4 ${isCurrent ? 'bg-code-current shadow-[inset_3px_0_0_var(--color-error)]' : ''}`}>
              <span aria-hidden="true" className={`w-14 shrink-0 select-none pr-4 text-right ${isCurrent ? 'font-bold text-error' : 'text-muted'}`}>
                {first != null ? first + i : ''}
              </span>
              {isCurrent && <span className="sr-only">Error line: </span>}
              {html ? <code dangerouslySetInnerHTML={{ __html: html[i] || ' ' }} /> : <code>{text || ' '}</code>}
            </div>
          )
        })}
      </pre>
    </div>
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
                <MaybeFiltered value={typeof value === 'string' ? value : JSON.stringify(value)} />
              </Meta>
            ))}
          </dl>
        </>
      )}
      {request.query != null && (
        <>
          <h3 className="mt-4 text-base">Query</h3>
          <JsonBlock value={request.query} label="Query" />
        </>
      )}
      {request.body != null && (
        <>
          <h3 className="mt-4 text-base">Body</h3>
          <JsonBlock value={request.body} label="Request body" />
        </>
      )}
    </Card>
  )
}

function MaybeFiltered({ value }) {
  if (value === '[Filtered]') {
    return <span className="rounded bg-sunken px-1.5 py-0.5 font-mono text-xs text-muted">[Filtered]</span>
  }
  return <code className="font-mono text-xs">{value}</code>
}

function formatJson(value) {
  if (typeof value !== 'string') return JSON.stringify(value, null, 2)
  try {
    const parsed = JSON.parse(value)
    return typeof parsed === 'object' && parsed !== null ? JSON.stringify(parsed, null, 2) : value
  } catch {
    return value
  }
}

function JsonBlock({ value, label }) {
  return (
    <div role="region" tabIndex={0} aria-label={label} className="mt-2 min-w-0 overflow-x-auto rounded-lg bg-code">
      <pre className="min-w-max p-3 font-mono text-xs leading-relaxed">{formatJson(value)}</pre>
    </div>
  )
}
