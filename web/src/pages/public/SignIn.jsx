import { useState } from 'react'
import { Link, Navigate, useLocation, useNavigate } from 'react-router'
import { DemoButton } from '../../components/DemoButton'
import { Alert, Button, Field } from '../../components/ui'
import { useDocumentTitle } from '../../hooks/useDocumentTitle'
import { api, fieldErrors, SESSION_EXPIRED_MESSAGE } from '../../lib/api'
import { useAuth } from '../../lib/auth'

export default function SignIn() {
  useDocumentTitle('Sign in')
  const { status, expired, signIn } = useAuth()
  const location = useLocation()
  const navigate = useNavigate()
  const [form, setForm] = useState({ email: '', password: '' })
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState(null)
  const [busy, setBusy] = useState(false)

  if (status === 'authed') return <Navigate to={location.state?.from || '/app/projects'} replace />

  const update = (e) => setForm({ ...form, [e.target.name]: e.target.value })

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setMessage(null)
    try {
      signIn(await api('/auth/login', { method: 'POST', body: form }))
      navigate(location.state?.from || '/app/projects', { replace: true })
    } catch (err) {
      const fields = fieldErrors(err)
      if (err.status !== 422) setMessage(err.message)
      // With both fields filled in, the server's "email or password is incorrect" is about the pair, not the email field.
      else if (form.email && form.password && !fields.password) setMessage(fields.email)
      else setErrors(fields)
      setBusy(false)
    }
  }

  return (
    <div className="mx-auto max-w-sm px-4 py-12 sm:py-16">
      <h1 className="text-3xl">Sign in</h1>
      <p className="mt-2 text-muted">
        New here?{' '}
        <Link to="/signup" state={location.state} className="font-semibold text-accent underline-offset-4 hover:underline">
          Create an account
        </Link>
      </p>

      {expired && !message && (
        <Alert tone="warning" className="mt-6">
          {SESSION_EXPIRED_MESSAGE}
        </Alert>
      )}

      <form noValidate onSubmit={submit} className="mt-6 space-y-4 rounded-card border border-line bg-surface p-5 sm:p-6">
        {message && <Alert tone="error">{message}</Alert>}
        <Field id="email" label="Email" type="email" autoComplete="email" value={form.email} onChange={update} error={errors.email} />
        <Field id="password" label="Password" type="password" autoComplete="current-password" value={form.password} onChange={update} error={errors.password} />
        <Button type="submit" className="w-full" busy={busy}>
          Sign in
        </Button>
      </form>

      <div className="mt-6">
        <p className="mb-2 text-center text-sm text-muted">Just looking around?</p>
        <DemoButton />
      </div>
    </div>
  )
}
