import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { Alert, Button, Card, ErrorState, Field, Skeleton } from '../../components/ui'
import { useDocumentTitle } from '../../hooks/useDocumentTitle'
import { api, fieldErrors } from '../../lib/api'
import { useAuth } from '../../lib/auth'
import AlertsCard from './AlertsCard'

const ROLES = [
  { value: 'admin', label: 'Admin' },
  { value: 'developer', label: 'Developer' },
]
const ROLE_LABEL = { admin: 'Admin', developer: 'Developer' }
const DEMO_NOTE = "The shared demo account can't change members or rotate keys. Create your own account to try this."

export default function ProjectSettings() {
  const { slug } = useParams()
  const { user } = useAuth()
  const [state, setState] = useState({ loading: true, error: null, project: null, members: [] })
  useDocumentTitle(state.project?.name || 'Project')

  const path = `/projects/${encodeURIComponent(slug)}`

  const [attempt, setAttempt] = useState(0)

  useEffect(() => {
    let active = true
    Promise.all([api(path), api(`${path}/members`)])
      .then(([project, members]) => active && setState({ loading: false, error: null, project: project.data, members: members.data }))
      .catch((err) => active && setState({ loading: false, error: err.message, project: null, members: [] }))
    return () => {
      active = false
    }
  }, [path, attempt])

  function load() {
    setState((s) => ({ ...s, loading: true, error: null }))
    setAttempt((n) => n + 1)
  }

  const setProject = (project) => setState((s) => ({ ...s, project }))
  const setMembers = (members) => setState((s) => ({ ...s, members }))

  if (state.loading && !state.project) {
    return (
      <div aria-busy="true" className="space-y-4">
        <span className="sr-only" role="status">
          Loading project…
        </span>
        <Skeleton className="h-10 w-64" />
        <Skeleton className="h-36" />
        <Skeleton className="h-56" />
      </div>
    )
  }
  if (state.error) {
    return (
      <>
        <BackLink />
        <div className="mt-6">
          <ErrorState title="Couldn't load this project" message={state.error} onRetry={load} />
        </div>
      </>
    )
  }

  const { project, members } = state
  const isAdmin = project.role === 'admin'
  // The server refuses these for the demo too; hiding them just avoids offering what can't work.
  const canManageAccess = isAdmin && !user?.is_demo

  return (
    <>
      <BackLink />
      <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1">
        <h1 className="min-w-0 text-3xl">{project.name}</h1>
        <span className="rounded-full bg-sunken px-2.5 py-0.5 text-xs font-medium text-muted">You&apos;re {project.role === 'admin' ? 'an admin' : 'a developer'}</span>
      </div>
      <p className="mt-2 text-muted">Project settings, SDK key, team and alerts.</p>
      <Link to={`/app/projects/${encodeURIComponent(project.slug)}/feed`} className="mt-2 inline-block text-sm font-semibold text-accent underline-offset-4 hover:underline">
        Open the live feed →
      </Link>

      <div className="mt-8 space-y-6">
        <KeyCard path={path} project={project} canRotate={canManageAccess} demo={isAdmin && user?.is_demo} onChange={setProject} />
        {isAdmin && <RenameCard path={path} project={project} onChange={setProject} />}
        <MembersCard path={path} isAdmin={canManageAccess} demo={isAdmin && user?.is_demo} members={members} onChange={setMembers} onReload={load} />
        <AlertsCard path={path} canManage={isAdmin && !user?.is_demo} demo={Boolean(user?.is_demo)} />
      </div>
    </>
  )
}

function BackLink() {
  return (
    <Link to="/app/projects" className="text-sm font-semibold text-accent underline-offset-4 hover:underline">
      ← All projects
    </Link>
  )
}

function KeyCard({ path, project, canRotate, demo, onChange }) {
  const [copyStatus, setCopyStatus] = useState('')
  const [confirming, setConfirming] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const rotateRef = useRef(null)
  const cancelRef = useRef(null)
  const keyRef = useRef(null)

  const wasConfirming = useRef(false)

  useEffect(() => {
    if (confirming) cancelRef.current?.focus()
    else if (wasConfirming.current) rotateRef.current?.focus()
    wasConfirming.current = confirming
  }, [confirming])

  async function copy() {
    try {
      await navigator.clipboard.writeText(project.public_key)
      setCopyStatus('Copied to clipboard.')
    } catch {
      // The async Clipboard API is blocked in some embedded and non-HTTPS contexts; selecting the text still lets execCommand copy it.
      window.getSelection()?.selectAllChildren(keyRef.current)
      const copied = document.execCommand?.('copy')
      setCopyStatus(copied ? 'Copied to clipboard.' : "Couldn't copy automatically. The key is selected, so press Ctrl+C or Cmd+C.")
    }
  }

  function cancel() {
    setConfirming(false)
  }

  async function rotate() {
    setBusy(true)
    setError(null)
    try {
      const { data } = await api(`${path}/rotate-key`, { method: 'POST' })
      onChange(data)
      setCopyStatus('')
      setConfirming(false)
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card title="Public key" description="Your SDK sends error reports with this key. It's safe to ship in client-side code.">
      <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center">
        <code ref={keyRef} className="block min-w-0 flex-1 select-all break-all rounded-lg bg-sunken px-3 py-2.5 font-mono text-sm">{project.public_key}</code>
        <Button variant="secondary" className="shrink-0" onClick={copy} aria-label="Copy public key">
          Copy
        </Button>
      </div>
      <p role="status" className="mt-2 min-h-5 text-sm text-muted">
        {copyStatus}
      </p>

      {error && (
        <Alert tone="error" className="mt-3">
          {error}
        </Alert>
      )}

      {demo && <p className="mt-3 text-sm text-muted">{DEMO_NOTE}</p>}

      {canRotate &&
        (confirming ? (
          <div role="group" aria-labelledby="rotate-confirm" className="mt-3 rounded-lg border border-error/30 bg-error-tint p-4">
            <p id="rotate-confirm" className="text-sm text-error">
              Rotate the key? Apps still using the current key will stop reporting errors until you update them.
            </p>
            <div className="mt-3 flex flex-wrap gap-2">
              <Button variant="danger" busy={busy} onClick={rotate}>
                Yes, rotate key
              </Button>
              <Button ref={cancelRef} variant="secondary" onClick={cancel} disabled={busy}>
                Cancel
              </Button>
            </div>
          </div>
        ) : (
          <Button ref={rotateRef} variant="danger" className="mt-3" onClick={() => setConfirming(true)}>
            Rotate key
          </Button>
        ))}
    </Card>
  )
}

function RenameCard({ path, project, onChange }) {
  const [name, setName] = useState(project.name)
  const [errors, setErrors] = useState({})
  const [status, setStatus] = useState(null)
  const [busy, setBusy] = useState(false)

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setStatus(null)
    try {
      const { data } = await api(path, { method: 'PATCH', body: { name } })
      onChange(data)
      setName(data.name)
      setStatus({ tone: 'success', text: 'Project renamed.' })
    } catch (err) {
      setErrors(fieldErrors(err))
      if (err.status !== 422) setStatus({ tone: 'error', text: err.message })
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card title="Project name">
      <form noValidate onSubmit={submit} className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start">
        <Field id="project-name" label="Name" className="flex-1" maxLength={80} value={name} onChange={(e) => setName(e.target.value)} error={errors.name} />
        <Button type="submit" variant="secondary" className="sm:mt-7" busy={busy}>
          Save name
        </Button>
      </form>
      {status && (
        <Alert tone={status.tone} className="mt-3">
          {status.text}
        </Alert>
      )}
    </Card>
  )
}

function MembersCard({ path, isAdmin, demo, members, onChange, onReload }) {
  const navigate = useNavigate()
  const [error, setError] = useState(null)
  const [busyId, setBusyId] = useState(null)
  const [removing, setRemoving] = useState(null)

  async function changeRole(member, role) {
    setBusyId(member.id)
    setError(null)
    try {
      const { data } = await api(`${path}/members/${member.id}`, { method: 'PATCH', body: { role } })
      if (data.is_you) onReload()
      else onChange(members.map((m) => (m.id === data.id ? data : m)))
    } catch (err) {
      setError(fieldErrors(err).role || err.message)
    } finally {
      setBusyId(null)
    }
  }

  function cancelRemove(member) {
    setRemoving(null)
    document.getElementById(`remove-${member.id}`)?.focus()
  }

  async function remove(member) {
    setBusyId(member.id)
    setError(null)
    try {
      await api(`${path}/members/${member.id}`, { method: 'DELETE' })
      setRemoving(null)
      if (member.is_you) navigate('/app/projects', { replace: true })
      else onChange(members.filter((m) => m.id !== member.id))
    } catch (err) {
      setError(fieldErrors(err).member || err.message)
    } finally {
      setBusyId(null)
    }
  }

  return (
    <Card
      title="Members"
      description={
        demo
          ? DEMO_NOTE
          : isAdmin
            ? 'Admins can rename the project, rotate its key and manage members. Developers can view the project and its key.'
            : 'Only admins can change who belongs to this project.'
      }
    >
      {error && (
        <Alert tone="error" className="mt-4">
          {error}
        </Alert>
      )}
      <ul className="mt-4 divide-y divide-line border-y border-line">
        {members.map((member) => (
          <li key={member.id} className="py-3">
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
              <div className="min-w-0">
                <p className="truncate font-medium">
                  {member.name}
                  {member.is_you && <span className="font-normal text-muted"> (you)</span>}
                </p>
                {member.email && <p className="truncate text-sm text-muted">{member.email}</p>}
              </div>
              {isAdmin ? (
                <div className="flex items-center gap-2">
                  <select
                    aria-label={`Role for ${member.name}`}
                    value={member.role}
                    disabled={busyId === member.id}
                    onChange={(e) => changeRole(member, e.target.value)}
                    className="min-h-11 rounded-lg border border-line-strong bg-surface px-3 text-sm"
                  >
                    {ROLES.map((r) => (
                      <option key={r.value} value={r.value}>
                        {r.label}
                      </option>
                    ))}
                  </select>
                  <Button id={`remove-${member.id}`} variant="ghost" aria-label={`Remove ${member.name}`} onClick={() => setRemoving(member.id)} disabled={busyId === member.id}>
                    Remove
                  </Button>
                </div>
              ) : (
                <span className="rounded-full bg-sunken px-2.5 py-0.5 text-xs font-medium text-muted">{ROLE_LABEL[member.role]}</span>
              )}
            </div>
            {removing === member.id && (
              <RemoveConfirm member={member} busy={busyId === member.id} onConfirm={() => remove(member)} onCancel={() => cancelRemove(member)} />
            )}
          </li>
        ))}
      </ul>
      {isAdmin && <AddMemberForm path={path} onAdded={onReload} />}
    </Card>
  )
}

function RemoveConfirm({ member, busy, onConfirm, onCancel }) {
  const cancelRef = useRef(null)
  useEffect(() => cancelRef.current?.focus(), [])

  return (
    <div role="group" aria-label={`Confirm removing ${member.name}`} className="mt-3 rounded-lg border border-error/30 bg-error-tint p-3">
      <p className="text-sm text-error">{member.is_you ? 'Remove yourself? You will lose access to this project.' : `Remove ${member.name} from this project?`}</p>
      <div className="mt-2 flex flex-wrap gap-2">
        <Button variant="danger" busy={busy} onClick={onConfirm}>
          Remove
        </Button>
        <Button ref={cancelRef} variant="secondary" onClick={onCancel} disabled={busy}>
          Cancel
        </Button>
      </div>
    </div>
  )
}

function AddMemberForm({ path, onAdded }) {
  const [form, setForm] = useState({ email: '', role: 'developer' })
  const [errors, setErrors] = useState({})
  const [status, setStatus] = useState(null)
  const [busy, setBusy] = useState(false)

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setStatus(null)
    try {
      const data = await api(`${path}/members`, { method: 'POST', body: form })
      setStatus({ tone: 'success', text: data.message })
      setForm({ ...form, email: '' })
      onAdded()
    } catch (err) {
      setErrors(fieldErrors(err))
      if (err.status !== 422) setStatus({ tone: 'error', text: err.message })
    } finally {
      setBusy(false)
    }
  }

  return (
    <form noValidate onSubmit={submit} className="mt-6">
      <h3 className="text-base">Add a member</h3>
      <p className="mt-1 text-sm text-muted">They need a BugWatcher Pings account with this email.</p>
      <div className="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_10rem_auto] sm:items-start">
        <Field
          id="member-email"
          label="Email"
          type="email"
          autoComplete="off"
          value={form.email}
          onChange={(e) => setForm({ ...form, email: e.target.value })}
          error={errors.email}
        />
        <Field id="member-role" label="Role" as="select" value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })} error={errors.role}>
          {ROLES.map((r) => (
            <option key={r.value} value={r.value}>
              {r.label}
            </option>
          ))}
        </Field>
        <Button type="submit" className="sm:mt-7" busy={busy}>
          Add member
        </Button>
      </div>
      {status && (
        <Alert tone={status.tone} className="mt-3">
          {status.text}
        </Alert>
      )}
    </form>
  )
}
