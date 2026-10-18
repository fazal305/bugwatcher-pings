import { useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router'
import { DemoButton } from '../../components/DemoButton'
import { Alert, Button, Field } from '../../components/ui'
import { useDocumentTitle } from '../../hooks/useDocumentTitle'
import { api, fieldErrors } from '../../lib/api'
import { useAuth } from '../../lib/auth'

export default function SignUp() {
  useDocumentTitle('Create an account')
  const { status, signIn } = useAuth()
  const navigate = useNavigate()
  const [form, setForm] = useState({ name: '', email: '', password: '', age_confirmed: false })
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState(null)
  const [busy, setBusy] = useState(false)

  if (status === 'authed') return <Navigate to="/app/projects" replace />

  const update = (e) => setForm({ ...form, [e.target.name]: e.target.type === 'checkbox' ? e.target.checked : e.target.value })

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setMessage(null)
    try {
      signIn(await api('/auth/register', { method: 'POST', body: form }))
      navigate('/app/projects', { replace: true })
    } catch (err) {
      setErrors(fieldErrors(err))
      setMessage(err.message)
      setBusy(false)
    }
  }

  return (
    <div className="mx-auto max-w-sm px-4 py-12 sm:py-16">
      <h1 className="text-3xl">Create an account</h1>
      <p className="mt-2 text-muted">
        Already have one?{' '}
        <Link to="/signin" className="font-semibold text-accent underline-offset-4 hover:underline">
          Sign in
        </Link>
      </p>

      <form noValidate onSubmit={submit} className="mt-6 space-y-4 rounded-card border border-line bg-surface p-5 sm:p-6">
        {message && <Alert tone="error">{message}</Alert>}
        <Field id="name" label="Name" autoComplete="name" value={form.name} onChange={update} error={errors.name} />
        <Field id="email" label="Email" type="email" autoComplete="email" value={form.email} onChange={update} error={errors.email} />
        <Field
          id="password"
          label="Password"
          type="password"
          autoComplete="new-password"
          hint="At least 8 characters."
          value={form.password}
          onChange={update}
          error={errors.password}
        />

        <div>
          <div className="flex items-start gap-3">
            <input
              id="age_confirmed"
              name="age_confirmed"
              type="checkbox"
              checked={form.age_confirmed}
              onChange={update}
              aria-invalid={errors.age_confirmed ? true : undefined}
              aria-describedby={errors.age_confirmed ? 'age_confirmed-error' : undefined}
              className="mt-0.5 size-5 shrink-0 accent-accent"
            />
            <label htmlFor="age_confirmed" className="text-sm">
              I&apos;m 16 or older and I agree to the{' '}
              <Link to="/terms" target="_blank" className="font-semibold text-accent underline underline-offset-2">
                Terms<span className="sr-only"> (opens in a new tab)</span>
              </Link>
              .
            </label>
          </div>
          {errors.age_confirmed && (
            <p id="age_confirmed-error" className="mt-1 text-sm text-error">
              {errors.age_confirmed}
            </p>
          )}
        </div>

        <Button type="submit" className="w-full" busy={busy}>
          Create account
        </Button>
      </form>

      <div className="mt-6">
        <p className="mb-2 text-center text-sm text-muted">Rather look around first?</p>
        <DemoButton />
      </div>
    </div>
  )
}
