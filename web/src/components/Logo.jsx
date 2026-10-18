export function LogoMark({ className = 'size-8' }) {
  return (
    <svg viewBox="0 0 32 32" className={className} aria-hidden="true">
      <rect width="32" height="32" rx="8" className="fill-accent" />
      <circle cx="11" cy="21" r="3" className="fill-paper" />
      <path
        d="M11 13.5a7.5 7.5 0 0 1 7.5 7.5M11 8a13 13 0 0 1 13 13"
        fill="none"
        className="stroke-paper"
        strokeWidth="2.6"
        strokeLinecap="round"
      />
    </svg>
  )
}

export function Wordmark() {
  return (
    <span className="flex items-center gap-2.5">
      <LogoMark />
      <span className="font-display text-lg font-semibold tracking-tight">BugWatcher Pings</span>
    </span>
  )
}
