import { useEffect, useEffectEvent, useLayoutEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { ConnectionPill, EventCard } from '../../components/feed'
import { Button, ErrorState, Skeleton } from '../../components/ui'
import { useConnectionStatus } from '../../hooks/useConnectionStatus'
import { useDocumentTitle } from '../../hooks/useDocumentTitle'
import { API_URL, api } from '../../lib/api'
import { ENVIRONMENTS, LEVELS } from '../../lib/feedOptions'
import { getEcho } from '../../lib/realtime'

const MAX_EVENTS = 500
const PAGE_SIZE = 50
const BACKFILL_PAGES = 10
const TOP_THRESHOLD = 24
const SLOW_AFTER_MS = 3000
export const LAST_FEED_KEY = 'bwp_last_feed'

// Survives navigating to an event and back, so the feed comes back with the same events and scroll position.
const cache = new Map()

const NO_FILTERS = { level: [], environment: [] }

const receivedMs = (event) => Date.parse(event.received_at)
const cursorOf = (event) => `${receivedMs(event)}-${event.id}`

function matches(event, filters) {
  return (!filters.level.length || filters.level.includes(event.level)) && (!filters.environment.length || filters.environment.includes(event.environment))
}

function eventsPath(slug, filters, before) {
  const params = new URLSearchParams({ limit: String(PAGE_SIZE) })
  if (before) params.set('before', before)
  filters.level.forEach((level) => params.append('level[]', level))
  filters.environment.forEach((env) => params.append('environment[]', env))
  return `/projects/${encodeURIComponent(slug)}/events?${params}`
}

function capped(items, nextCursor) {
  if (items.length <= MAX_EVENTS) return { items, nextCursor }
  const kept = items.slice(0, MAX_EVENTS)
  return { items: kept, nextCursor: cursorOf(kept[kept.length - 1]) }
}

function prefersReducedMotion() {
  return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
}

export default function Feed() {
  const { slug } = useParams()
  return <FeedView key={slug} slug={slug} />
}

function FeedView({ slug }) {
  const navigate = useNavigate()
  const [cached] = useState(() => cache.get(slug))
  const [filters, setFilters] = useState(cached?.filters || NO_FILTERS)
  const filtersKey = JSON.stringify(filters)
  const [feed, setFeed] = useState(() =>
    cached ? { status: 'ready', items: cached.items, nextCursor: cached.nextCursor, error: null } : { status: 'loading', items: [], nextCursor: null, error: null },
  )
  const [attempt, setAttempt] = useState(0)
  const [slowLoad, setSlowLoad] = useState(null)
  const [projects, setProjects] = useState({ list: [], current: null, error: null })
  const [expanded, setExpanded] = useState(() => cached?.expanded || new Set())
  const [entering, setEntering] = useState(() => new Set())
  const [unseen, setUnseen] = useState(0)
  const [failed, setFailed] = useState(false)
  const [loadingOlder, setLoadingOlder] = useState(false)
  const [olderError, setOlderError] = useState(null)
  const [now, setNow] = useState(() => Date.now())
  const status = useConnectionStatus()

  const listRef = useRef(null)
  const latestHeadingRef = useRef(null)
  const anchorRef = useRef(null)
  const bufferRef = useRef([])
  const scrollYRef = useRef(cached?.scrollY || 0)
  const stateRef = useRef({ feed, filters, expanded })

  useLayoutEffect(() => {
    stateRef.current = { feed, filters, expanded }
  })

  useDocumentTitle(projects.current ? `Live feed · ${projects.current.name}` : 'Live feed')

  useEffect(() => {
    try {
      localStorage.setItem(LAST_FEED_KEY, slug)
    } catch {
      // Only a convenience for the next visit.
    }
  }, [slug])

  useEffect(() => {
    let active = true
    Promise.all([api('/projects'), api(`/projects/${encodeURIComponent(slug)}`)])
      .then(([list, project]) => active && setProjects({ list: list.data, current: project.data, error: null }))
      .catch((err) => active && setProjects({ list: [], current: null, error: err.message }))
    return () => {
      active = false
    }
  }, [slug])

  useEffect(() => {
    const id = setInterval(() => setNow(Date.now()), 15_000)
    return () => clearInterval(id)
  }, [])

  useLayoutEffect(() => {
    // Scroll anchoring is done by hand below; the browser's own would apply the same correction a second time.
    const root = document.documentElement
    const previous = root.style.overflowAnchor
    root.style.overflowAnchor = 'none'
    window.scrollTo(0, cached ? cached.scrollY : 0)
    return () => {
      root.style.overflowAnchor = previous
      const { feed: latest, filters: latestFilters, expanded: latestExpanded } = stateRef.current
      if (latest.status === 'ready') {
        cache.set(slug, { items: latest.items, nextCursor: latest.nextCursor, filters: latestFilters, expanded: latestExpanded, scrollY: scrollYRef.current })
      }
    }
  }, [slug, cached])

  useEffect(() => {
    const onScroll = () => {
      scrollYRef.current = window.scrollY
      if (window.scrollY <= TOP_THRESHOLD) setUnseen(0)
    }
    window.addEventListener('scroll', onScroll, { passive: true })
    return () => window.removeEventListener('scroll', onScroll)
  }, [])

  const prepend = useEffectEvent((incoming) => {
    const { feed: current, filters: currentFilters } = stateRef.current
    const known = new Set(current.items.map((e) => e.id))
    const fresh = incoming.filter((e) => !known.has(e.id) && matches(e, currentFilters))
    if (!fresh.length) return

    const list = listRef.current
    const active = document.activeElement
    const focusedCard = list && active && list.contains(active) ? active.closest('[data-event-id]') : null
    const inspecting = Boolean(focusedCard) || expanded.size > 0
    const atTop = window.scrollY <= TOP_THRESHOLD

    if (atTop && !inspecting) {
      setEntering(prefersReducedMotion() ? new Set() : new Set(fresh.map((e) => e.id)))
    } else {
      let anchor = focusedCard
      if (!anchor && list) {
        anchor = [...list.querySelectorAll('[data-event-id]')].find((el) => el.getBoundingClientRect().bottom > 0) || null
      }
      if (anchor) anchorRef.current = { id: anchor.dataset.eventId, top: anchor.getBoundingClientRect().top }
      setUnseen((n) => n + fresh.length)
    }
    setFeed((f) => ({ ...f, ...capped([...fresh, ...f.items], f.nextCursor) }))
  })

  useLayoutEffect(() => {
    const anchor = anchorRef.current
    if (!anchor) return
    anchorRef.current = null
    const el = listRef.current?.querySelector(`[data-event-id="${CSS.escape(anchor.id)}"]`)
    if (!el) return
    const delta = el.getBoundingClientRect().top - anchor.top
    if (delta) window.scrollBy({ top: delta, behavior: 'instant' })
  }, [feed.items])

  const receive = useEffectEvent((events) => {
    if (stateRef.current.feed.status === 'ready') prepend(events)
    else bufferRef.current.push(...events)
  })

  // Fetches pages newer than what's shown until it reaches a known event, to cover anything missed while disconnected.
  const backfill = useEffectEvent(async () => {
    if (stateRef.current.feed.status !== 'ready') return
    const { items } = stateRef.current.feed
    const requestFilters = stateRef.current.filters
    const known = new Set(items.map((e) => e.id))
    const newest = items.slice(0, PAGE_SIZE).reduce((max, e) => Math.max(max, receivedMs(e)), -Infinity)
    const found = []
    try {
      let cursor = null
      for (let page = 0; page < BACKFILL_PAGES; page++) {
        const data = await api(eventsPath(slug, requestFilters, cursor))
        const stop = data.data.findIndex((e) => known.has(e.id) || receivedMs(e) < newest)
        found.push(...(stop === -1 ? data.data : data.data.slice(0, stop)))
        if (stop !== -1 || !data.next_cursor) break
        cursor = data.next_cursor
      }
    } catch {
      return
    }
    if (found.length && stateRef.current.filters === requestFilters) prepend(found)
  })

  useEffect(() => {
    const echo = getEcho()
    const name = `project.${slug}`
    const channel = echo.private(name)
    channel.listen('.ping.processed', (event) => receive([event]))
    channel.subscribed(() => {
      setFailed(false)
      backfill()
    })
    channel.error(() => setFailed(true))
    return () => echo.leave(name)
  }, [slug])

  useEffect(() => {
    if (stateRef.current.feed.status !== 'loading') return
    let active = true
    const requestFilters = JSON.parse(filtersKey)
    bufferRef.current = []
    api(eventsPath(slug, requestFilters, null))
      .then((data) => {
        if (!active) return
        const ids = new Set(data.data.map((e) => e.id))
        const buffered = bufferRef.current.filter((e) => !ids.has(e.id) && matches(e, requestFilters)).reverse()
        bufferRef.current = []
        setFeed({ status: 'ready', error: null, ...capped([...buffered, ...data.data], data.next_cursor) })
      })
      .catch((err) => active && setFeed({ status: 'error', items: [], nextCursor: null, error: err.message }))
    return () => {
      active = false
    }
  }, [slug, filtersKey, attempt])

  const loadKey = `${filtersKey}:${attempt}`
  const slow = feed.status === 'loading' && slowLoad === loadKey

  useEffect(() => {
    if (feed.status !== 'loading') return
    const id = setTimeout(() => setSlowLoad(loadKey), SLOW_AFTER_MS)
    return () => clearTimeout(id)
  }, [feed.status, loadKey])

  function reload() {
    setFeed({ status: 'loading', items: [], nextCursor: null, error: null })
    setAttempt((n) => n + 1)
  }

  function toggleFilter(group, value) {
    setFilters((f) => ({ ...f, [group]: f[group].includes(value) ? f[group].filter((v) => v !== value) : [...f[group], value] }))
    setFeed({ status: 'loading', items: [], nextCursor: null, error: null })
    setExpanded(new Set())
    setUnseen(0)
  }

  function clearFilters() {
    setFilters(NO_FILTERS)
    setFeed({ status: 'loading', items: [], nextCursor: null, error: null })
    setUnseen(0)
  }

  function toggleExpanded(id) {
    setExpanded((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  function jumpToLatest() {
    setUnseen(0)
    window.scrollTo({ top: 0, behavior: prefersReducedMotion() ? 'instant' : 'smooth' })
    latestHeadingRef.current?.focus({ preventScroll: true })
  }

  async function loadOlder() {
    setLoadingOlder(true)
    setOlderError(null)
    try {
      const data = await api(eventsPath(slug, filters, feed.nextCursor))
      setFeed((f) => {
        const known = new Set(f.items.map((e) => e.id))
        return { ...f, items: [...f.items, ...data.data.filter((e) => !known.has(e.id))], nextCursor: data.next_cursor }
      })
    } catch (err) {
      setOlderError(err.message)
    } finally {
      setLoadingOlder(false)
    }
  }

  const filtered = filters.level.length > 0 || filters.environment.length > 0

  return (
    <>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h1 className="text-3xl">Live feed</h1>
          <p className="mt-1 text-muted [overflow-wrap:anywhere]">{projects.current ? projects.current.name : 'New errors appear here as they happen.'}</p>
        </div>
        <ConnectionPill status={status} failed={failed} />
      </div>

      {projects.current?.role === 'admin' && <TestErrorButton slug={slug} />}

      {projects.list.length > 1 && (
        <div className="mt-4">
          <label htmlFor="feed-project" className="block text-sm font-medium">
            Project
          </label>
          <select
            id="feed-project"
            value={slug}
            onChange={(e) => navigate(`/app/projects/${encodeURIComponent(e.target.value)}/feed`)}
            className="mt-1.5 min-h-11 w-full max-w-sm rounded-lg border border-line-strong bg-surface px-3 text-base sm:text-sm"
          >
            {projects.list.map((p) => (
              <option key={p.slug} value={p.slug}>
                {p.name}
              </option>
            ))}
          </select>
        </div>
      )}

      <div className="mt-5 space-y-3">
        <FilterGroup label="Severity" options={LEVELS} selected={filters.level} onToggle={(v) => toggleFilter('level', v)} />
        <FilterGroup label="Environment" options={ENVIRONMENTS} selected={filters.environment} onToggle={(v) => toggleFilter('environment', v)} />
        {filtered && (
          <button type="button" onClick={clearFilters} className="min-h-9 text-sm font-semibold text-accent underline-offset-4 hover:underline">
            Clear filters
          </button>
        )}
      </div>

      <h2 ref={latestHeadingRef} tabIndex={-1} className="sr-only">
        Latest events
      </h2>
      <p className="sr-only" aria-live="polite">
        {unseen > 0 ? `${unseen} new ${unseen === 1 ? 'event' : 'events'} above.` : ''}
      </p>

      {unseen > 0 && (
        <button
          type="button"
          onClick={jumpToLatest}
          className="fixed left-1/2 top-20 z-30 min-h-10 -translate-x-1/2 rounded-full bg-accent px-4 text-sm font-semibold text-on-accent shadow-lg hover:bg-accent-strong lg:top-6"
        >
          ↑ {unseen} new {unseen === 1 ? 'event' : 'events'}
        </button>
      )}

      <div className="mt-6">
        {feed.status === 'loading' ? (
          <div aria-busy="true" className="space-y-3">
            <p role="status" className={slow ? 'rounded-lg border border-line-strong bg-surface px-4 py-3 text-sm' : 'sr-only'}>
              {slow ? 'Still loading — the free-tier API may be waking up (up to ~50s).' : 'Loading events…'}
            </p>
            <Skeleton className="h-28" />
            <Skeleton className="h-28" />
            <Skeleton className="h-28" />
          </div>
        ) : feed.status === 'error' ? (
          <ErrorState title="Couldn't load events" message={feed.error} onRetry={reload} />
        ) : feed.items.length === 0 ? (
          filtered ? (
            <div className="rounded-card border border-dashed border-line-strong bg-surface px-6 py-10 text-center">
              <h2 className="text-lg">No events match these filters</h2>
              <p className="mx-auto mt-1 max-w-md text-sm text-muted">New events that match will still appear here as they arrive.</p>
              <Button variant="secondary" className="mt-4" onClick={clearFilters}>
                Clear filters
              </Button>
            </div>
          ) : (
            <EmptyFeed project={projects.current} />
          )
        ) : (
          <>
            <ol ref={listRef} className="space-y-3" aria-label="Events, newest first">
              {feed.items.map((event) => (
                <li key={event.id}>
                  <EventCard event={event} slug={slug} now={now} expanded={expanded.has(event.id)} onToggle={toggleExpanded} entering={entering.has(event.id)} />
                </li>
              ))}
            </ol>
            <div className="mt-6 text-center">
              {olderError && (
                <p role="alert" className="mb-3 text-sm text-error">
                  {olderError}
                </p>
              )}
              {feed.nextCursor ? (
                <Button variant="secondary" busy={loadingOlder} onClick={loadOlder}>
                  Load older events
                </Button>
              ) : (
                <p className="text-sm text-muted">That&apos;s everything in the last 30 days.</p>
              )}
            </div>
          </>
        )}
      </div>
    </>
  )
}

function TestErrorButton({ slug }) {
  const [busy, setBusy] = useState(false)
  const [result, setResult] = useState(null)

  async function fire() {
    setBusy(true)
    setResult(null)
    try {
      const data = await api(`/projects/${encodeURIComponent(slug)}/test-error`, { method: 'POST' })
      setResult({ ok: true, text: data.message })
    } catch (err) {
      setResult({ ok: false, text: err.message })
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="mt-4 flex flex-wrap items-center gap-x-3 gap-y-2">
      <Button variant="secondary" busy={busy} onClick={fire}>
        Fire a test error
      </Button>
      <p role="status" className={`text-sm ${result && !result.ok ? 'text-error' : 'text-muted'}`}>
        {result ? result.text : 'Sends a labelled [Test] error through the real ingest pipeline.'}
      </p>
    </div>
  )
}

function FilterGroup({ label, options, selected, onToggle }) {
  return (
    <div role="group" aria-label={`Filter by ${label.toLowerCase()}`} className="flex flex-wrap items-center gap-2">
      <span className="w-full text-sm font-medium sm:w-24">{label}</span>
      {options.map((option) => {
        const on = selected.includes(option.value)
        return (
          <button
            key={option.value}
            type="button"
            aria-pressed={on}
            onClick={() => onToggle(option.value)}
            className={`min-h-9 rounded-full border px-3 text-sm font-medium ${on ? 'border-accent bg-accent-tint text-accent-strong' : 'border-line-strong bg-surface text-muted hover:text-ink'}`}
          >
            {option.label}
          </button>
        )
      })}
    </div>
  )
}

function EmptyFeed({ project }) {
  const [copied, setCopied] = useState('')
  const [timestamp] = useState(() => new Date().toISOString())
  const command = project
    ? `curl -X POST ${API_URL}/v1/ping \\
  -H "Content-Type: application/json" \\
  -H "X-BugWatcher-Key: ${project.public_key}" \\
  -d '{"message": "Test ping from curl", "level": "error", "environment": "production", "timestamp": "${timestamp}"}'`
    : null

  async function copy() {
    try {
      await navigator.clipboard.writeText(command)
      setCopied('Copied to clipboard.')
    } catch {
      setCopied("Couldn't copy automatically. Select the command and copy it.")
    }
  }

  return (
    <div className="rounded-card border border-dashed border-line-strong bg-surface px-5 py-8 sm:px-8">
      <h2 className="text-lg">No errors yet</h2>
      <p className="mt-1 text-sm text-muted">
        Errors show up here within a moment of being reported. Send a test ping to see one arrive, or set up an SDK from the{' '}
        {project ? (
          <Link to={`/app/projects/${encodeURIComponent(project.slug)}`} className="font-semibold text-accent underline-offset-4 hover:underline">
            project settings
          </Link>
        ) : (
          'project settings'
        )}
        .
      </p>
      {command && (
        <>
          <pre className="mt-4 overflow-x-auto rounded-lg bg-sunken p-3 font-mono text-xs leading-relaxed">
            <code>{command}</code>
          </pre>
          <div className="mt-3 flex flex-wrap items-center gap-3">
            <Button variant="secondary" onClick={copy}>
              Copy command
            </Button>
            <p role="status" className="text-sm text-muted">
              {copied}
            </p>
          </div>
        </>
      )}
    </div>
  )
}
