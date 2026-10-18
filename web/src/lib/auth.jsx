import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { api, getToken, setToken } from './api'
import { resetRealtime } from './realtime'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [status, setStatus] = useState(() => (getToken() ? 'checking' : 'guest'))
  const [expired, setExpired] = useState(false)

  useEffect(() => {
    if (status !== 'checking') return
    let active = true
    api('/me')
      .then((data) => {
        if (!active) return
        setUser(data.user)
        setStatus('authed')
      })
      .catch((error) => {
        if (!active) return
        setStatus(error.status === 401 ? 'guest' : 'unreachable')
      })
    return () => {
      active = false
    }
  }, [status])

  useEffect(() => {
    const onExpired = () => {
      resetRealtime()
      setUser(null)
      setExpired(true)
      setStatus('guest')
    }
    window.addEventListener('session-expired', onExpired)
    return () => window.removeEventListener('session-expired', onExpired)
  }, [])

  const signIn = useCallback(({ token, user: nextUser }) => {
    setToken(token)
    setUser(nextUser)
    setExpired(false)
    setStatus('authed')
  }, [])

  const signOut = useCallback(async () => {
    try {
      await api('/auth/logout', { method: 'POST' })
    } catch {
      // The token may already be invalid; signing out locally still applies.
    }
    resetRealtime()
    setToken(null)
    setUser(null)
    setStatus('guest')
  }, [])

  const retry = useCallback(() => setStatus('checking'), [])

  const value = useMemo(() => ({ user, status, expired, signIn, signOut, retry }), [user, status, expired, signIn, signOut, retry])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

// oxlint-disable-next-line react/only-export-components
export function useAuth() {
  return useContext(AuthContext)
}
