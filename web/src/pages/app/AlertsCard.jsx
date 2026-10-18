import { useEffect, useId, useRef, useState } from 'react'
import { Alert, Button, Card, Field, Skeleton } from '../../components/ui'
import { api, fieldErrors } from '../../lib/api'
import { ENVIRONMENTS, LEVELS } from '../../lib/feedOptions'
import { fullTime } from '../../lib/time'

const KINDS = [
  { value: 'slack', label: 'Slack' },
  { value: 'discord', label: 'Discord' },
  { value: 'generic', label: 'Signed webhook (JSON)' },
]
const KIND_LABEL = Object.fromEntries(KINDS.map((k) => [k.value, k.label]))
const LEVEL_LABEL = Object.fromEntries(LEVELS.map((l) => [l.value, l.label]))
const ENV_LABEL = Object.fromEntries(ENVIRONMENTS.map((e) => [e.value, e.label]))
const MIN_LEVELS = ['fatal', 'error', 'warning', 'info']

const URL_HINTS = {
  slack: 'In Slack, create an app at api.slack.com/apps, turn on Incoming Webhooks, add a webhook to a channel and paste its URL here (https://hooks.slack.com/services/…).',
  discord: 'In Discord, open Server Settings → Integrations → Webhooks, create a webhook for a channel and copy its URL (https://discord.com/api/webhooks/…).',
  generic: 'An https:// URL on a public host and the standard port. Each request is signed with a secret shown once after you save.',
}

const DEMO_NOTE = "The shared demo account can't add, change or test alerts. Create your own account to try this."

const emptyForm = { kind: 'slack', name: '', url: '', min_level: 'error', environments: ['production'], cooldown_seconds: '300' }

function formFrom(endpoint) {
  return {
    kind: endpoint.kind,
    name: endpoint.name,
    url: '',
    min_level: endpoint.min_level,
    environments: endpoint.environments,
    cooldown_seconds: String(endpoint.cooldown_seconds),
  }
}

export default function AlertsCard({ path, canManage, demo }) {
  const [state, setState] = useState({ loading: true, error: null, endpoints: [] })
  const [attempt, setAttempt] = useState(0)
  const [editing, setEditing] = useState(null)
  const [secret, setSecret] = useState(null)
  const addRef = useRef(null)

  useEffect(() => {
    let active = true
    api(`${path}/webhooks`)
      .then((data) => active && setState({ loading: false, error: null, endpoints: data.data }))
      .catch((err) => active && setState({ loading: false, error: err.message, endpoints: [] }))
    return () => {
      active = false
    }
  }, [path, attempt])

  const replace = (endpoint) => setState((s) => ({ ...s, endpoints: s.endpoints.map((e) => (e.id === endpoint.id ? endpoint : e)) }))
  const remove = (id) => setState((s) => ({ ...s, endpoints: s.endpoints.filter((e) => e.id !== id) }))

  function created(endpoint) {
    setState((s) => ({ ...s, endpoints: [...s.endpoints, endpoint] }))
    setEditing(null)
    if (endpoint.signing_secret) setSecret({ name: endpoint.name, value: endpoint.signing_secret })
  }

  function closeForm() {
    const wasNew = editing === 'new'
    setEditing(null)
    if (wasNew) requestAnimationFrame(() => addRef.current?.focus())
  }

  return (
    <Card
      title="Alerts"
      description={
        demo
          ? DEMO_NOTE
          : canManage
            ? 'Send errors to Slack, Discord or your own endpoint as soon as they are processed. Repeats of an issue are held back for the cooldown; new issues and regressions always go out.'
            : 'Only admins can add or change alerts.'
      }
    >
      {secret && (
        <Alert tone="success" className="mt-4">
          <p className="font-semibold">Signing secret for {secret.name}</p>
          <p className="mt-1">Copy it now: it won&apos;t be shown again. Use it to check the X-BugWatcher-Signature header.</p>
          <SecretValue value={secret.value} />
          <Button variant="secondary" className="mt-2 min-h-9" onClick={() => setSecret(null)}>
            I&apos;ve saved it
          </Button>
        </Alert>
      )}

      {state.loading ? (
        <div aria-busy="true" className="mt-4 space-y-3">
          <span className="sr-only" role="status">
            Loading alerts…
          </span>
          <Skeleton className="h-24" />
        </div>
      ) : state.error ? (
        <div role="alert" className="mt-4 rounded-lg border border-error/30 bg-error-tint px-4 py-3 text-sm text-error">
          <p>{state.error}</p>
          <Button
            variant="secondary"
            className="mt-3 min-h-9"
            onClick={() => {
              setState({ loading: true, error: null, endpoints: [] })
              setAttempt((n) => n + 1)
            }}
          >
            Try again
          </Button>
        </div>
      ) : state.endpoints.length === 0 && editing !== 'new' ? (
        <p className="mt-4 rounded-lg border border-dashed border-line-strong px-4 py-6 text-center text-sm text-muted">No alerts yet.</p>
      ) : (
        <ul className="mt-4 space-y-3">
          {state.endpoints.map((endpoint) =>
            editing === endpoint.id ? (
              <li key={endpoint.id}>
                <EndpointForm path={path} endpoint={endpoint} onSaved={(saved) => {
                    replace(saved)
                    setEditing(null)
                  }} onCancel={() => setEditing(null)} />
              </li>
            ) : (
              <li key={endpoint.id}>
                <EndpointRow path={path} endpoint={endpoint} canManage={canManage} onChange={replace} onRemoved={remove} onEdit={() => setEditing(endpoint.id)} />
              </li>
            ),
          )}
        </ul>
      )}

      {canManage &&
        !state.loading &&
        !state.error &&
        (editing === 'new' ? (
          <div className="mt-4">
            <EndpointForm path={path} onSaved={created} onCancel={closeForm} />
          </div>
        ) : (
          <Button ref={addRef} className="mt-4" onClick={() => {
              setSecret(null)
              setEditing('new')
            }}>
            Add an alert
          </Button>
        ))}
    </Card>
  )
}

function SecretValue({ value }) {
  const [status, setStatus] = useState('')
  const ref = useRef(null)
  async function copy() {
    try {
      await navigator.clipboard.writeText(value)
      setStatus('Copied.')
    } catch {
      window.getSelection()?.selectAllChildren(ref.current)
      setStatus('Selected. Press Ctrl+C or Cmd+C to copy.')
    }
  }
  return (
    <div className="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center">
      <code ref={ref} className="block min-w-0 flex-1 select-all break-all rounded bg-surface px-2 py-1.5 font-mono text-xs text-ink">
        {value}
      </code>
      <Button variant="secondary" className="min-h-9 shrink-0" onClick={copy}>
        Copy secret
      </Button>
      <span role="status" className="text-xs">
        {status}
      </span>
    </div>
  )
}

function StatusBadge({ status }) {
  const tone = {
    success: 'bg-accent-tint text-accent-strong',
    failed: 'bg-error-tint text-error',
    throttled: 'bg-sunken text-muted',
  }
  const label = { success: 'Delivered', failed: 'Failed', throttled: 'Held back' }
  if (!status) return <span className="rounded-full bg-sunken px-2 py-0.5 text-xs font-medium text-muted">Not sent yet</span>
  return <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${tone[status] || tone.throttled}`}>{label[status] || status}</span>
}

function EndpointRow({ path, endpoint, canManage, onChange, onRemoved, onEdit }) {
  const base = `${path}/webhooks/${endpoint.id}`
  const [busy, setBusy] = useState(null)
  const [message, setMessage] = useState(null)
  const [confirming, setConfirming] = useState(false)
  const [showDeliveries, setShowDeliveries] = useState(false)
  const [deliveriesKey, setDeliveriesKey] = useState(0)
  const deliveriesId = useId()
  const deleteRef = useRef(null)
  const cancelRef = useRef(null)

  useEffect(() => {
    if (confirming) cancelRef.current?.focus()
  }, [confirming])

  async function toggleEnabled() {
    setBusy('toggle')
    setMessage(null)
    try {
      const { data } = await api(base, { method: 'PATCH', body: { enabled: !endpoint.enabled } })
      onChange(data)
    } catch (err) {
      setMessage({ tone: 'error', text: err.message })
    } finally {
      setBusy(null)
    }
  }

  async function sendTest() {
    setBusy('test')
    setMessage(null)
    try {
      const { data } = await api(`${base}/test`, { method: 'POST' })
      onChange(data.endpoint)
      const ok = data.delivery.status === 'success'
      setMessage({ tone: ok ? 'success' : 'error', text: ok ? `Test message sent in ${data.delivery.duration_ms} ms.` : data.message })
      setDeliveriesKey((n) => n + 1)
    } catch (err) {
      setMessage({ tone: 'error', text: err.message })
    } finally {
      setBusy(null)
    }
  }

  async function destroy() {
    setBusy('delete')
    setMessage(null)
    try {
      await api(base, { method: 'DELETE' })
      onRemoved(endpoint.id)
    } catch (err) {
      setMessage({ tone: 'error', text: err.message })
      setBusy(null)
    }
  }

  function cancelDelete() {
    setConfirming(false)
    requestAnimationFrame(() => deleteRef.current?.focus())
  }

  return (
    <article className="min-w-0 rounded-lg border border-line p-4" aria-label={`${endpoint.name} (${KIND_LABEL[endpoint.kind]})`}>
      <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
        <div className="min-w-0">
          <h3 className="font-sans text-base font-semibold [overflow-wrap:anywhere]">{endpoint.name}</h3>
          <p className="mt-0.5 text-sm text-muted">
            {KIND_LABEL[endpoint.kind]}
            {!endpoint.enabled && ' · paused'}
          </p>
          <code className="mt-1 block font-mono text-xs text-muted [overflow-wrap:anywhere]">{endpoint.url_masked}</code>
        </div>
        {canManage ? (
          <button
            type="button"
            role="switch"
            aria-checked={endpoint.enabled}
            aria-label={`Send alerts to ${endpoint.name}`}
            disabled={busy === 'toggle'}
            onClick={toggleEnabled}
            className="inline-flex min-h-11 items-center gap-2 rounded-lg px-1 text-sm font-medium disabled:opacity-60"
          >
            <span aria-hidden="true" className={`relative inline-block h-6 w-10 rounded-full transition-colors ${endpoint.enabled ? 'bg-accent' : 'bg-line-strong'}`}>
              <span className={`absolute top-0.5 size-5 rounded-full bg-surface shadow transition-transform ${endpoint.enabled ? 'translate-x-[1.125rem]' : 'translate-x-0.5'}`} />
            </span>
            {endpoint.enabled ? 'On' : 'Off'}
          </button>
        ) : (
          <span className="rounded-full bg-sunken px-2.5 py-0.5 text-xs font-medium text-muted">{endpoint.enabled ? 'On' : 'Off'}</span>
        )}
      </div>

      <dl className="mt-3 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[8rem_minmax(0,1fr)]">
        <dt className="text-muted">Sends</dt>
        <dd className="min-w-0">
          {LEVEL_LABEL[endpoint.min_level]}
          {endpoint.min_level !== 'fatal' && ' and worse'} in {endpoint.environments.map((e) => ENV_LABEL[e] || e).join(', ')}
        </dd>
        <dt className="text-muted">Cooldown</dt>
        <dd>{endpoint.cooldown_seconds === 0 ? 'None' : `${endpoint.cooldown_seconds.toLocaleString()} s per issue`}</dd>
        <dt className="text-muted">Last result</dt>
        <dd className="flex min-w-0 flex-wrap items-center gap-2">
          <StatusBadge status={endpoint.last_status} />
          {endpoint.last_error && <span className="text-error [overflow-wrap:anywhere]">{endpoint.last_error}</span>}
          {endpoint.last_delivered_at && (
            <span className="text-muted">
              last delivered <time dateTime={endpoint.last_delivered_at} className="font-mono">{fullTime(endpoint.last_delivered_at)}</time>
            </span>
          )}
        </dd>
      </dl>

      {message && (
        <Alert tone={message.tone} className="mt-3">
          {message.text}
        </Alert>
      )}

      <div className="mt-3 flex flex-wrap gap-2">
        {canManage && (
          <>
            <Button variant="secondary" className="min-h-9 px-3" busy={busy === 'test'} onClick={sendTest}>
              Send test
            </Button>
            <Button variant="secondary" className="min-h-9 px-3" onClick={onEdit}>
              Edit
            </Button>
            {!confirming && (
              <Button ref={deleteRef} variant="danger" className="min-h-9 px-3" onClick={() => setConfirming(true)}>
                Delete
              </Button>
            )}
          </>
        )}
        <Button variant="ghost" className="min-h-9 px-3" aria-expanded={showDeliveries} aria-controls={deliveriesId} onClick={() => setShowDeliveries((v) => !v)}>
          {showDeliveries ? 'Hide deliveries' : 'Show deliveries'}
        </Button>
      </div>

      {confirming && (
        <div role="group" aria-label={`Confirm deleting ${endpoint.name}`} className="mt-3 rounded-lg border border-error/30 bg-error-tint p-3">
          <p className="text-sm text-error">Delete this alert? Errors will stop being sent to it.</p>
          <div className="mt-2 flex flex-wrap gap-2">
            <Button variant="danger" busy={busy === 'delete'} onClick={destroy}>
              Yes, delete
            </Button>
            <Button ref={cancelRef} variant="secondary" onClick={cancelDelete} disabled={busy === 'delete'}>
              Cancel
            </Button>
          </div>
        </div>
      )}

      <div id={deliveriesId} hidden={!showDeliveries}>
        {showDeliveries && <Deliveries key={deliveriesKey} path={`${base}/deliveries`} />}
      </div>
    </article>
  )
}

function Deliveries({ path }) {
  const [state, setState] = useState({ loading: true, error: null, rows: [] })
  const [attempt, setAttempt] = useState(0)

  useEffect(() => {
    let active = true
    api(path)
      .then((data) => active && setState({ loading: false, error: null, rows: data.data }))
      .catch((err) => active && setState({ loading: false, error: err.message, rows: [] }))
    return () => {
      active = false
    }
  }, [path, attempt])

  if (state.loading) return <Skeleton className="mt-3 h-20" />
  if (state.error) {
    return (
      <div role="alert" className="mt-3 text-sm text-error">
        {state.error}{' '}
        <button type="button" className="font-semibold underline" onClick={() => {
            setState({ loading: true, error: null, rows: [] })
            setAttempt((n) => n + 1)
          }}>
          Try again
        </button>
      </div>
    )
  }
  if (!state.rows.length) return <p className="mt-3 text-sm text-muted">Nothing sent yet.</p>

  return (
    <div role="region" tabIndex={0} aria-label="Recent deliveries" className="mt-3 min-w-0 overflow-x-auto rounded-lg border border-line">
      <table className="w-full min-w-[34rem] text-left text-sm">
        <caption className="sr-only">The last 50 deliveries, newest first. Dispatch delay is the time from processing the error to starting the request.</caption>
        <thead className="bg-sunken text-xs text-muted">
          <tr>
            <th scope="col" className="px-3 py-2 font-medium">
              Time
            </th>
            <th scope="col" className="px-3 py-2 font-medium">
              Result
            </th>
            <th scope="col" className="px-3 py-2 text-right font-medium">
              HTTP
            </th>
            <th scope="col" className="px-3 py-2 text-right font-medium">
              Dispatch delay
            </th>
            <th scope="col" className="px-3 py-2 text-right font-medium">
              Duration
            </th>
          </tr>
        </thead>
        <tbody className="divide-y divide-line font-mono text-xs">
          {state.rows.map((row) => (
            <tr key={row.id}>
              <td className="whitespace-nowrap px-3 py-2">
                <time dateTime={row.created_at}>{fullTime(row.created_at)}</time>
                {row.test && <span className="ml-2 font-sans text-muted">test</span>}
              </td>
              <td className="px-3 py-2 font-sans">
                <StatusBadge status={row.status} />
              </td>
              <td className="px-3 py-2 text-right">{row.http_status ?? '–'}</td>
              <td className="px-3 py-2 text-right">{row.dispatch_delay_ms != null ? `${row.dispatch_delay_ms} ms` : '–'}</td>
              <td className="px-3 py-2 text-right">{row.duration_ms != null ? `${row.duration_ms} ms` : '–'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function EndpointForm({ path, endpoint, onSaved, onCancel }) {
  const editing = Boolean(endpoint)
  const [form, setForm] = useState(() => (endpoint ? formFrom(endpoint) : emptyForm))
  const [errors, setErrors] = useState({})
  const [status, setStatus] = useState(null)
  const [busy, setBusy] = useState(false)
  const formId = useId()
  const firstRef = useRef(null)

  useEffect(() => {
    firstRef.current?.focus()
  }, [])

  const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }))

  function toggleEnvironment(value) {
    setForm((f) => ({ ...f, environments: f.environments.includes(value) ? f.environments.filter((v) => v !== value) : [...f.environments, value] }))
  }

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setStatus(null)
    const cooldown = form.cooldown_seconds.trim()
    const body = {
      name: form.name,
      min_level: form.min_level,
      environments: form.environments,
      cooldown_seconds: /^\d+$/.test(cooldown) ? Number(cooldown) : cooldown,
      ...(editing ? {} : { kind: form.kind }),
      ...(!editing || form.url.trim() ? { url: form.url.trim() } : {}),
    }
    try {
      const { data } = await api(editing ? `${path}/webhooks/${endpoint.id}` : `${path}/webhooks`, { method: editing ? 'PATCH' : 'POST', body })
      onSaved(data)
    } catch (err) {
      const found = fieldErrors(err)
      const environments = found.environments || Object.entries(found).find(([key]) => key.startsWith('environments.'))?.[1]
      setErrors({ ...found, environments })
      if (err.status !== 422) setStatus(err.message)
      setBusy(false)
    }
  }

  const envError = errors.environments
  return (
    <form noValidate onSubmit={submit} aria-labelledby={`${formId}-title`} className="rounded-lg border border-line-strong bg-paper p-4">
      <h3 id={`${formId}-title`} className="text-base">
        {editing ? `Edit ${endpoint.name}` : 'Add an alert'}
      </h3>
      <div className="mt-3 grid gap-4 sm:grid-cols-2">
        {editing ? (
          <p className="text-sm sm:col-span-2">
            <span className="text-muted">Kind:</span> {KIND_LABEL[endpoint.kind]}
          </p>
        ) : (
          <Field ref={firstRef} id={`${formId}-kind`} label="Send to" as="select" value={form.kind} onChange={set('kind')} error={errors.kind}>
            {KINDS.map((k) => (
              <option key={k.value} value={k.value}>
                {k.label}
              </option>
            ))}
          </Field>
        )}
        <Field
          ref={editing ? firstRef : undefined}
          id={`${formId}-name`}
          label="Name"
          maxLength={80}
          placeholder="#ops-alerts"
          value={form.name}
          onChange={set('name')}
          error={errors.name}
        />
        <Field
          id={`${formId}-url`}
          className="sm:col-span-2"
          label="Webhook URL"
          type="url"
          inputMode="url"
          autoComplete="off"
          spellCheck={false}
          placeholder={editing ? `Leave empty to keep ${endpoint.url_masked}` : 'https://'}
          hint={URL_HINTS[form.kind]}
          value={form.url}
          onChange={set('url')}
          error={errors.url}
        />
        <Field id={`${formId}-level`} label="Lowest severity to send" as="select" value={form.min_level} onChange={set('min_level')} error={errors.min_level}>
          {MIN_LEVELS.map((level) => (
            <option key={level} value={level}>
              {LEVEL_LABEL[level]}
              {level !== 'fatal' ? ' and worse' : ' only'}
            </option>
          ))}
        </Field>
        <Field
          id={`${formId}-cooldown`}
          label="Cooldown per issue (seconds)"
          hint="0 sends every event."
          inputMode="numeric"
          value={form.cooldown_seconds}
          onChange={set('cooldown_seconds')}
          error={errors.cooldown_seconds}
        />
        <fieldset className="sm:col-span-2" aria-describedby={envError ? `${formId}-env-error` : undefined}>
          <legend className="text-sm font-medium">Environments</legend>
          <div className="mt-1.5 flex flex-wrap gap-x-5 gap-y-2">
            {ENVIRONMENTS.map((env) => (
              <label key={env.value} className="inline-flex min-h-11 items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  className="size-4 accent-accent"
                  checked={form.environments.includes(env.value)}
                  onChange={() => toggleEnvironment(env.value)}
                  aria-invalid={envError ? true : undefined}
                />
                {env.label}
              </label>
            ))}
          </div>
          {envError && (
            <p id={`${formId}-env-error`} className="mt-1 text-sm text-error">
              {envError}
            </p>
          )}
        </fieldset>
      </div>
      {status && (
        <Alert tone="error" className="mt-3">
          {status}
        </Alert>
      )}
      <div className="mt-4 flex flex-wrap gap-2">
        <Button type="submit" busy={busy}>
          {editing ? 'Save changes' : 'Add alert'}
        </Button>
        <Button variant="secondary" onClick={onCancel} disabled={busy}>
          Cancel
        </Button>
      </div>
    </form>
  )
}
