import { Link, Navigate, NavLink, Outlet, useLocation } from 'react-router'
import { LogoMark, Wordmark } from '../components/Logo'
import { Button, ErrorState, OfflineBanner, Skeleton } from '../components/ui'
import { useAuth } from '../lib/auth'

const NAV = [
  { to: '/app', label: 'Live feed', end: true },
  { to: '/app/projects', label: 'Projects' },
]

export function RequireAuth({ children }) {
  const { status, retry } = useAuth()
  const location = useLocation()

  if (status === 'checking') {
    return (
      <div className="mx-auto max-w-5xl space-y-4 p-6" aria-busy="true">
        <span className="sr-only" role="status">
          Loading your account…
        </span>
        <Skeleton className="h-10 w-48" />
        <Skeleton className="h-32" />
      </div>
    )
  }
  if (status === 'unreachable') {
    return (
      <div className="mx-auto max-w-lg p-6 pt-20">
        <ErrorState
          title="Can't reach BugWatcher Pings"
          message="We couldn't contact the server to restore your session. Check your connection and try again."
          onRetry={retry}
        />
      </div>
    )
  }
  if (status !== 'authed') return <Navigate to="/signin" replace state={{ from: location.pathname }} />
  return children
}

export default function AppLayout() {
  const { user, signOut } = useAuth()

  return (
    <div className="min-h-dvh lg:grid lg:grid-cols-[15rem_minmax(0,1fr)]">
      <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-surface focus:px-3 focus:py-2">
        Skip to content
      </a>

      <aside className="hidden border-r border-line bg-surface lg:sticky lg:top-0 lg:flex lg:h-dvh lg:flex-col">
        <Link to="/" className="px-5 py-5" aria-label="BugWatcher Pings home">
          <Wordmark />
        </Link>
        <nav aria-label="Main" className="flex-1 space-y-1 px-3">
          {NAV.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              className={({ isActive }) =>
                `block rounded-lg px-3 py-2 text-sm font-medium ${isActive ? 'bg-accent-tint text-accent-strong' : 'text-muted hover:bg-sunken hover:text-ink'}`
              }
            >
              {item.label}
            </NavLink>
          ))}
        </nav>
        <div className="border-t border-line p-4">
          <p className="truncate text-sm font-semibold">{user?.name}</p>
          <p className="truncate text-xs text-muted">{user?.is_demo ? 'Shared demo account' : user?.email}</p>
          <Button variant="ghost" className="mt-2 -ml-2 min-h-9 px-2" onClick={signOut}>
            Sign out
          </Button>
        </div>
      </aside>

      <div className="flex min-w-0 flex-col">
        <OfflineBanner />
        <header className="sticky top-0 z-20 flex items-center gap-2 border-b border-line bg-paper/90 px-4 py-3 backdrop-blur sm:gap-3 sm:px-6 lg:hidden">
          <Link to="/" aria-label="BugWatcher Pings home" className="shrink-0">
            <LogoMark />
          </Link>
          <nav aria-label="Main" className="flex min-w-0 gap-1 text-sm font-medium">
            {NAV.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.end}
                className={({ isActive }) => `whitespace-nowrap rounded-lg px-2.5 py-1.5 ${isActive ? 'bg-accent-tint text-accent-strong' : 'text-muted'}`}
              >
                {item.label}
              </NavLink>
            ))}
          </nav>
          <Button variant="ghost" className="ml-auto min-h-9 shrink-0 px-2.5" onClick={signOut}>
            Sign out
          </Button>
        </header>
        {user?.is_demo && (
          <p role="note" className="border-b border-warning/20 bg-warning-tint px-4 py-2 text-center text-xs text-warning sm:px-6">
            You&apos;re using the shared demo account. Anyone trying the demo can see and change what&apos;s here.
          </p>
        )}
        <main id="main" className="mx-auto w-full max-w-5xl flex-1 px-4 py-8 sm:px-8 sm:py-10">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
