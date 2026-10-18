import { Link, NavLink, Outlet } from 'react-router'
import { LogoMark, Wordmark } from '../components/Logo'

const NAV = [{ to: '/app', label: 'Live feed', end: true }]

export default function AppLayout() {
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
      </aside>

      <div className="flex min-w-0 flex-col">
        <header className="sticky top-0 z-20 flex items-center gap-3 border-b border-line bg-paper/90 px-4 py-3 backdrop-blur sm:px-6 lg:hidden">
          <Link to="/" aria-label="BugWatcher Pings home">
            <LogoMark />
          </Link>
          <nav aria-label="Main" className="flex gap-1 text-sm font-medium">
            {NAV.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.end}
                className={({ isActive }) => `rounded-lg px-3 py-1.5 ${isActive ? 'bg-accent-tint text-accent-strong' : 'text-muted'}`}
              >
                {item.label}
              </NavLink>
            ))}
          </nav>
        </header>
        <main id="main" className="mx-auto w-full max-w-5xl flex-1 px-4 py-8 sm:px-8 sm:py-10">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
