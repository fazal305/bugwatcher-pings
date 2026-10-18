import { memo, useId } from 'react'
import { Link } from 'react-router'
import { ENVIRONMENTS, LEVELS } from '../lib/feedOptions'
import { clockTime, fullTime, relativeTime } from '../lib/time'

const LEVEL_LABEL = Object.fromEntries(LEVELS.map((l) => [l.value, l.label]))
const ENV_LABEL = Object.fromEntries(ENVIRONMENTS.map((e) => [e.value, e.label]))

const LEVEL_STYLE = {
  fatal: { badge: 'bg-fatal-tint text-fatal', edge: 'border-l-fatal' },
  error: { badge: 'bg-error-tint text-error', edge: 'border-l-error' },
  warning: { badge: 'bg-warning-tint text-warning', edge: 'border-l-warning' },
  info: { badge: 'bg-info-tint text-info', edge: 'border-l-info' },
}

const tag = 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold'

export function SeverityBadge({ level }) {
  return <span className={`${tag} font-mono uppercase tracking-wide ${LEVEL_STYLE[level]?.badge || 'bg-sunken text-muted'}`}>{LEVEL_LABEL[level] || level}</span>
}

export function EnvironmentBadge({ environment }) {
  return <span className={`${tag} border border-line-strong font-medium text-muted`}>{ENV_LABEL[environment] || environment}</span>
}

export function EventTags({ event }) {
  return (
    <>
      {event.critical && <span className={`${tag} bg-fatal text-surface`}>Critical</span>}
      {event.is_new_issue && <span className={`${tag} bg-accent-tint text-accent-strong`}>New issue</span>}
      {event.is_regression && <span className={`${tag} bg-warning-tint text-warning`}>Regression</span>}
    </>
  )
}

export function Timestamp({ iso, now, className = '' }) {
  return (
    <time dateTime={iso} title={iso} className={className}>
      <span className="font-mono">{clockTime(iso)}</span>
      {now !== undefined && <span className="text-muted"> · {relativeTime(iso, now)}</span>}
    </time>
  )
}

export const EventCard = memo(function EventCard({ event, slug, now, expanded, onToggle, entering }) {
  const detailsId = useId()
  const style = LEVEL_STYLE[event.level] || LEVEL_STYLE.info

  return (
    <article
      data-event-id={event.id}
      className={`relative min-w-0 rounded-card border border-l-4 border-line bg-surface p-4 focus-within:border-line-strong sm:p-5 ${style.edge} ${entering ? 'feed-enter' : ''}`}
    >
      <div className="flex flex-wrap items-center gap-1.5">
        <SeverityBadge level={event.level} />
        <EnvironmentBadge environment={event.environment} />
        <EventTags event={event} />
        <Timestamp iso={event.received_at} now={now} className="ml-auto text-xs sm:text-sm" />
      </div>

      <h3 className="mt-2 font-sans text-base font-semibold leading-snug [overflow-wrap:anywhere]">
        <Link
          to={`/app/projects/${encodeURIComponent(slug)}/events/${encodeURIComponent(event.id)}`}
          state={{ fromFeed: true }}
          className="rounded after:absolute after:inset-0 after:rounded-card hover:underline focus-visible:outline-offset-4"
        >
          {event.message}
        </Link>
      </h3>

      <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted">
        {event.culprit && <code className="min-w-0 font-mono text-xs [overflow-wrap:anywhere]">{event.culprit}</code>}
        <span>
          {event.event_count.toLocaleString()} {event.event_count === 1 ? 'event' : 'events'}
        </span>
        <button
          type="button"
          aria-expanded={expanded}
          aria-controls={detailsId}
          onClick={() => onToggle(event.id)}
          className="relative z-10 ml-auto min-h-9 rounded-lg px-2 text-sm font-semibold text-accent hover:bg-sunken"
        >
          {expanded ? 'Less' : 'More'}
          <span className="sr-only"> about this event</span>
        </button>
      </div>

      <dl id={detailsId} hidden={!expanded} className="relative z-10 mt-3 grid gap-x-4 gap-y-1 border-t border-line pt-3 text-sm sm:grid-cols-[8rem_minmax(0,1fr)]">
        <dt className="text-muted">Issue</dt>
        <dd className="min-w-0 [overflow-wrap:anywhere]">{event.issue_title}</dd>
        <dt className="text-muted">Occurred</dt>
        <dd>
          <time dateTime={event.occurred_at} title={event.occurred_at} className="font-mono">
            {fullTime(event.occurred_at)}
          </time>
        </dd>
        <dt className="text-muted">Received</dt>
        <dd>
          <time dateTime={event.received_at} title={event.received_at} className="font-mono">
            {fullTime(event.received_at)}
          </time>
        </dd>
        <dt className="text-muted">Event ID</dt>
        <dd className="min-w-0 font-mono text-xs [overflow-wrap:anywhere]">{event.id}</dd>
      </dl>
    </article>
  )
})

const STATUS = {
  live: { label: 'Live', dot: 'bg-accent', text: 'Receiving new errors as they happen.' },
  connecting: { label: 'Connecting', dot: 'bg-warning animate-pulse', text: 'Connecting to live updates.' },
  reconnecting: { label: 'Reconnecting', dot: 'bg-warning animate-pulse', text: 'Connection lost. Trying again; missed errors will be filled in.' },
  offline: { label: 'Offline', dot: 'bg-muted', text: 'Live updates are paused until the connection is back.' },
}

export function ConnectionPill({ status, failed }) {
  const current = failed ? { ...STATUS.offline, text: "Live updates couldn't start. Reload the page to try again." } : STATUS[status]
  return (
    <p aria-live="polite" role="status" title={current.text} className="inline-flex min-h-8 items-center gap-2 rounded-full border border-line-strong bg-surface px-3 text-sm font-semibold">
      <span aria-hidden="true" className={`size-2 rounded-full ${current.dot}`} />
      {current.label}
      <span className="sr-only">. {current.text}</span>
    </p>
  )
}
