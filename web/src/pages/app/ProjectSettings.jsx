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

// The async Clipboard API is blocked in some embedded and non-HTTPS contexts; selecting the text still lets execCommand copy it.
async function copyWithFallback(text, element) {
  try {
    await navigator.clipboard.writeText(text)
    return true
  } catch {
    window.getSelection()?.selectAllChildren(element)
    return Boolean(document.execCommand?.('copy'))
  }
}

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
    const copied = await copyWithFallback(project.public_key, keyRef.current)
    setCopyStatus(copied ? 'Copied to clipboard.' : "Couldn't copy automatically. The key is selected, so press Ctrl+C or Cmd+C.")
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
      {isAdmin && <Invitations path={path} />}
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

const formatDate = (iso) => new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })

function Invitations({ path }) {
  const [list, setList] = useState({ loading: true, error: null, items: [] })
  const [attempt, setAttempt] = useState(0)
  const [form, setForm] = useState({ email: '', role: 'developer' })
  const [errors, setErrors] = useState({})
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const [created, setCreated] = useState(null)
  const [copyStatus, setCopyStatus] = useState('')
  const [revoking, setRevoking] = useState(null)
  const [busyId, setBusyId] = useState(null)
  const linkRef = useRef(null)
  const copyRef = useRef(null)

  useEffect(() => {
    let active = true
    api(`${path}/invitations`)
      .then(({ data }) => active && setList({ loading: false, error: null, items: data }))
      .catch((err) => active && setList({ loading: false, error: err.message, items: [] }))
    return () => {
      active = false
    }
  }, [path, attempt])

  useEffect(() => {
    if (created) copyRef.current?.focus()
  }, [created])

  const reload = () => setAttempt((n) => n + 1)

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setError(null)
    try {
      const data = await api(`${path}/invitations`, { method: 'POST', body: form })
      setCreated({ email: data.invitation.email, url: data.invite_url })
      setCopyStatus('')
      setForm({ ...form, email: '' })
      reload()
    } catch (err) {
      setErrors(fieldErrors(err))
      if (err.status !== 422) setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  async function copy() {
    const copied = await copyWithFallback(created.url, linkRef.current)
    setCopyStatus(copied ? 'Copied to clipboard.' : "Couldn't copy automatically. The link is selected, so press Ctrl+C or Cmd+C.")
  }

  function cancelRevoke(invitation) {
    setRevoking(null)
    document.getElementById(`revoke-${invitation.id}`)?.focus()
  }

  async function revoke(invitation) {
    setBusyId(invitation.id)
    setError(null)
    try {
      await api(`${path}/invitations/${invitation.id}`, { method: 'DELETE' })
      setRevoking(null)
      setList((l) => ({ ...l, items: l.items.filter((i) => i.id !== invitation.id) }))
      if (created?.email === invitation.email) setCreated(null)
      document.getElementById('invite-email')?.focus()
    } catch (err) {
      setError(err.message)
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div className="mt-6">
      <form noValidate onSubmit={submit}>
        <h3 className="text-base">Invite a member</h3>
        <p className="mt-1 text-sm text-muted">You&apos;ll get a link to send them. They join by opening it while signed in with this email address.</p>
        <div className="mt-3 grid gap-3 sm:grid-cols-[minmax(0,1fr)_10rem_auto] sm:items-start">
          <Field
            id="invite-email"
            label="Email"
            type="email"
            autoComplete="off"
            value={form.email}
            onChange={(e) => setForm({ ...form, email: e.target.value })}
            error={errors.email}
          />
          <Field id="invite-role" label="Role" as="select" value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })} error={errors.role}>
            {ROLES.map((r) => (
              <option key={r.value} value={r.value}>
                {r.label}
              </option>
            ))}
          </Field>
          <Button type="submit" className="sm:mt-7" busy={busy}>
            Create invite link
          </Button>
        </div>
      </form>

      {error && (
        <Alert tone="error" className="mt-3">
          {error}
        </Alert>
      )}

      {created && (
        <div className="mt-4 rounded-lg border border-accent/30 bg-accent-tint p-4">
          <p role="status" className="text-sm font-semibold text-accent-strong">
            Invite link for {created.email}
          </p>
          <p className="mt-1 text-sm text-ink">Copy this link and send it to them. It works once and expires in 7 days.</p>
          <div className="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center">
            <code ref={linkRef} className="block min-w-0 flex-1 select-all break-all rounded-lg bg-surface px-3 py-2.5 font-mono text-sm">
              {created.url}
            </code>
            <Button ref={copyRef} variant="secondary" className="shrink-0" onClick={copy} aria-label={`Copy invite link for ${created.email}`}>
              Copy link
            </Button>
          </div>
          <p role="status" className="mt-2 min-h-5 text-sm text-muted">
            {copyStatus}
          </p>
        </div>
      )}

      <h3 className="mt-6 text-base">Pending invitations</h3>
      {list.loading ? (
        <div aria-busy="true" className="mt-3">
          <span className="sr-only" role="status">
            Loading invitations…
          </span>
          <Skeleton className="h-12" />
        </div>
      ) : list.error ? (
        <div className="mt-3">
          <ErrorState title="Couldn't load invitations" message={list.error} onRetry={reload} />
        </div>
      ) : list.items.length === 0 ? (
        <p className="mt-1 text-sm text-muted">No pending invitations.</p>
      ) : (
        <ul className="mt-3 divide-y divide-line border-y border-line">
          {list.items.map((invitation) => (
            <li key={invitation.id} className="py-3">
              <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                <div className="min-w-0">
                  <p className="truncate font-medium">{invitation.email}</p>
                  <p className="text-sm text-muted">
                    {ROLE_LABEL[invitation.role]} · expires {formatDate(invitation.expires_at)}
                    {invitation.invited_by && ` · invited by ${invitation.invited_by}`}
                  </p>
                </div>
                <Button
                  id={`revoke-${invitation.id}`}
                  variant="ghost"
                  aria-label={`Revoke invitation for ${invitation.email}`}
                  onClick={() => setRevoking(invitation.id)}
                  disabled={busyId === invitation.id}
                >
                  Revoke
                </Button>
              </div>
              {revoking === invitation.id && (
                <RevokeConfirm invitation={invitation} busy={busyId === invitation.id} onConfirm={() => revoke(invitation)} onCancel={() => cancelRevoke(invitation)} />
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

function RevokeConfirm({ invitation, busy, onConfirm, onCancel }) {
  const cancelRef = useRef(null)
  useEffect(() => cancelRef.current?.focus(), [])

  return (
    <div role="group" aria-label={`Confirm revoking the invitation for ${invitation.email}`} className="mt-3 rounded-lg border border-error/30 bg-error-tint p-3">
      <p className="text-sm text-error">Revoke this invitation? Its link will stop working.</p>
      <div className="mt-2 flex flex-wrap gap-2">
        <Button variant="danger" busy={busy} onClick={onConfirm}>
          Revoke
        </Button>
        <Button ref={cancelRef} variant="secondary" onClick={onCancel} disabled={busy}>
          Cancel
        </Button>
      </div>
    </div>
  )
}
