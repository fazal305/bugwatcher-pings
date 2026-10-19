import { useEffect, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router'
import { Alert, Button, ErrorState, LinkButton, Skeleton } from '../../components/ui'
import { useDocumentTitle } from '../../hooks/useDocumentTitle'
import { api } from '../../lib/api'
import { useAuth } from '../../lib/auth'

const ROLE_LABEL = { admin: 'an admin', developer: 'a developer' }

const formatDate = (iso) => new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' })

export default function Invite() {
  useDocumentTitle('Invitation')
  const { token } = useParams()
  const { status, user, signOut, retry } = useAuth()
  const navigate = useNavigate()
  const [state, setState] = useState({ phase: 'loading' })
  const [attempt, setAttempt] = useState(0)
  const [accepting, setAccepting] = useState({ busy: false, error: null })
  const headingRef = useRef(null)

  const tokenPath = `/invitations/${encodeURIComponent(token)}`

  useEffect(() => {
    let active = true
    api(tokenPath)
      .then((invitation) => active && setState({ phase: 'ready', invitation }))
      .catch((err) => active && setState(err.status === 404 ? { phase: 'invalid' } : { phase: 'error', message: err.message }))
    return () => {
      active = false
    }
  }, [tokenPath, attempt])

  // Moves focus to the new heading whenever the page changes state, so screen readers hear the outcome.
  useEffect(() => {
    if (state.phase !== 'loading') headingRef.current?.focus()
  }, [state.phase])

  function reload() {
    setState({ phase: 'loading' })
    setAttempt((n) => n + 1)
  }

  async function accept() {
    setAccepting({ busy: true, error: null })
    try {
      const { project } = await api(`${tokenPath}/accept`, { method: 'POST' })
      navigate(`/app/projects/${encodeURIComponent(project.slug)}/feed`, { replace: true })
    } catch (err) {
      if (err.status === 404) setState({ phase: 'invalid' })
      setAccepting({ busy: false, error: err.status === 404 ? null : err })
    }
  }

  async function switchAccount() {
    setAccepting({ busy: false, error: null })
    await signOut()
  }

  if (state.phase === 'loading') {
    return (
      <div className="mx-auto max-w-lg px-4 py-12 sm:py-16" aria-busy="true">
        <span className="sr-only" role="status">
          Loading invitation…
        </span>
        <Skeleton className="h-9 w-64" />
        <Skeleton className="mt-4 h-32" />
      </div>
    )
  }

  if (state.phase === 'invalid') {
    return (
      <div className="mx-auto max-w-lg px-4 py-12 sm:py-16">
        <h1 ref={headingRef} tabIndex={-1} className="text-3xl outline-none">
          This invitation can&apos;t be used
        </h1>
        <Alert tone="error" className="mt-6">
          This invitation link is invalid or has expired.
        </Alert>
        <p className="mt-4 text-muted">
          Links work once and expire after 7 days. Check that you copied the whole link, or ask a project admin to send you a new one.
        </p>
        <LinkButton to={status === 'authed' ? '/app/projects' : '/'} variant="secondary" className="mt-6">
          {status === 'authed' ? 'Go to your projects' : 'Back to the home page'}
        </LinkButton>
      </div>
    )
  }

  if (state.phase === 'error') {
    return (
      <div className="mx-auto max-w-lg px-4 py-12 sm:py-16">
        <h1 ref={headingRef} tabIndex={-1} className="text-3xl outline-none">
          Invitation
        </h1>
        <div className="mt-6">
          <ErrorState title="Couldn't load this invitation" message={state.message} onRetry={reload} />
        </div>
      </div>
    )
  }

  const { invitation } = state
  const from = `/invite/${token}`
  const error = accepting.error

  return (
    <div className="mx-auto max-w-lg px-4 py-12 sm:py-16">
      <p className="text-sm font-semibold text-accent">You&apos;re invited</p>
      <h1 ref={headingRef} tabIndex={-1} className="mt-1 break-words text-3xl outline-none">
        Join {invitation.project_name}
      </h1>
      <p className="mt-3 text-muted">
        You&apos;ve been invited to join this project on BugWatcher Pings as {ROLE_LABEL[invitation.role]}. The invitation expires on{' '}
        {formatDate(invitation.expires_at)}.
      </p>

      <div className="mt-6 rounded-card border border-line bg-surface p-5 sm:p-6">
        {status === 'checking' ? (
          <div aria-busy="true">
            <span className="sr-only" role="status">
              Checking your account…
            </span>
            <Skeleton className="h-11" />
          </div>
        ) : status === 'unreachable' ? (
          <ErrorState title="Can't reach BugWatcher Pings" message="We couldn't check whether you're signed in. Try again in a moment." onRetry={retry} />
        ) : status !== 'authed' ? (
          <>
            <p className="text-sm">Sign in with the email address this invitation was sent to, or create an account with it.</p>
            <div className="mt-4 flex flex-col gap-3 sm:flex-row">
              <LinkButton to="/signin" state={{ from }} className="flex-1">
                Sign in to accept
              </LinkButton>
              <LinkButton to="/signup" state={{ from }} variant="secondary" className="flex-1">
                Create an account
              </LinkButton>
            </div>
          </>
        ) : user?.is_demo ? (
          <>
            <Alert tone="warning">The shared demo account can&apos;t accept invitations. Sign out, then sign in with your own account to accept this one.</Alert>
            <Button variant="secondary" className="mt-4" onClick={switchAccount}>
              Sign out
            </Button>
          </>
        ) : (
          <>
            <p className="text-sm">
              Signed in as <span className="break-all font-semibold">{user?.email}</span>.
            </p>
            {error && (
              <Alert tone="error" className="mt-4">
                {error.message}
                {error.status === 403 && ' If you have another account, sign out and sign in with it. If not, create one with the invited address.'}
              </Alert>
            )}
            <div className="mt-4 flex flex-col gap-3 sm:flex-row">
              <Button className="flex-1" busy={accepting.busy} onClick={accept}>
                Accept invitation
              </Button>
              {error?.status === 403 && (
                <Button variant="secondary" className="flex-1" onClick={switchAccount}>
                  Sign out
                </Button>
              )}
            </div>
          </>
        )}
      </div>
    </div>
  )
}
