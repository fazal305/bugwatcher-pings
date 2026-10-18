import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { Alert, Button, Card, Field } from '../../components/ui'
import { useDocumentTitle } from '../../hooks/useDocumentTitle'
import { api, fieldErrors } from '../../lib/api'

export default function NewProject() {
  useDocumentTitle('New project')
  const navigate = useNavigate()
  const [name, setName] = useState('')
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState(null)
  const [busy, setBusy] = useState(false)

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setMessage(null)
    try {
      const { data } = await api('/projects', { method: 'POST', body: { name } })
      navigate(`/app/projects/${encodeURIComponent(data.slug)}`)
    } catch (err) {
      setErrors(fieldErrors(err))
      setMessage(err.status === 422 ? null : err.message)
      setBusy(false)
    }
  }

  return (
    <div className="max-w-xl">
      <Link to="/app/projects" className="text-sm font-semibold text-accent underline-offset-4 hover:underline">
        ← All projects
      </Link>
      <h1 className="mt-3 text-3xl">New project</h1>
      <p className="mt-2 text-muted">You&apos;ll be its admin. You can rename it and invite your team later.</p>

      <Card className="mt-8">
        <form noValidate onSubmit={submit} className="space-y-4">
          {message && <Alert tone="error">{message}</Alert>}
          <Field
            id="name"
            label="Project name"
            hint="For example, the app or site that will send errors."
            maxLength={80}
            value={name}
            onChange={(e) => setName(e.target.value)}
            error={errors.name}
          />
          <Button type="submit" busy={busy}>
            Create project
          </Button>
        </form>
      </Card>
    </div>
  )
}
