import { Link, Outlet } from 'react-router'
import { Wordmark } from '../components/Logo'

export default function PublicLayout() {
  return (
    <div className="flex min-h-dvh flex-col">
      <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-surface focus:px-3 focus:py-2">
        Skip to content
      </a>
      <header className="border-b border-line">
        <div className="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
          <Link to="/" aria-label="BugWatcher Pings home">
            <Wordmark />
          </Link>
          <nav aria-label="Account" className="text-sm font-semibold">
            <Link to="/app" className="rounded-lg px-3 py-2 hover:bg-sunken">
              Dashboard
            </Link>
          </nav>
        </div>
      </header>
      <main id="main" className="flex-1">
        <Outlet />
      </main>
      <footer className="border-t border-line">
        <div className="mx-auto max-w-5xl px-4 py-6 text-sm text-muted sm:px-6">
          <p>© 2026 Fazal Abbas</p>
        </div>
      </footer>
    </div>
  )
}
