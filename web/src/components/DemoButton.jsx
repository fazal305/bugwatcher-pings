import { useState } from 'react'
import { useNavigate } from 'react-router'
import { api } from '../lib/api'
import { useAuth } from '../lib/auth'
import { Alert, Button } from './ui'

export function DemoButton({ className = '' }) {
  const { signIn } = useAuth()
  const navigate = useNavigate()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  async function start() {
    setBusy(true)
    setError(null)
    try {
      signIn(await api('/auth/demo', { method: 'POST' }))
      navigate('/app/projects', { replace: true })
    } catch (err) {
      setError(err.status === 404 ? "The demo isn't available right now." : err.message)
      setBusy(false)
    }
  }

  return (
    <div className={className}>
      <Button variant="secondary" className="w-full" busy={busy} onClick={start}>
        Try the demo
      </Button>
      {error && (
        <Alert tone="error" className="mt-3">
          {error}
        </Alert>
      )}
    </div>
  )
}
