import { execFileSync } from 'node:child_process'
import { mkdirSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { API, DEMO_STATE } from './helpers'

/** Brings the demo project back to its sample data and signs into the demo once for the tests that need a session. */
export default async function globalSetup() {
  const artisan = (...args) => execFileSync(process.env.PHP_BINARY || 'php', [join(process.cwd(), '../api/artisan'), ...args], { stdio: 'inherit' })
  artisan('migrate', '--force')
  artisan('demo:reset', '--hours=0')

  const res = await fetch(`${API}/auth/demo`, { method: 'POST', headers: { Accept: 'application/json' } })
  if (!res.ok) throw new Error(`Demo sign-in failed with ${res.status}; is DEMO_LOGIN_ENABLED=true in api/.env?`)
  const { token } = await res.json()

  mkdirSync(dirname(DEMO_STATE), { recursive: true })
  writeFileSync(
    DEMO_STATE,
    JSON.stringify({ cookies: [], origins: [{ origin: 'http://localhost:5473', localStorage: [{ name: 'bwp_token', value: token }] }] }),
  )
}
