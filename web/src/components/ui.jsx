import { Link } from 'react-router'
import { useOnline } from '../hooks/useOnline'

const buttonBase =
  'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg px-4 text-sm font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60'

const buttonVariants = {
  primary: 'bg-accent text-on-accent hover:bg-accent-strong',
  secondary: 'border border-line-strong bg-surface text-ink hover:bg-sunken',
  ghost: 'text-ink hover:bg-sunken',
  danger: 'border border-error/40 bg-surface text-error hover:bg-error-tint',
}

function buttonClass(variant, className) {
  return `${buttonBase} ${buttonVariants[variant]} ${className}`
}

export function Button({ variant = 'primary', busy = false, className = '', type = 'button', disabled, children, ...props }) {
  return (
    <button type={type} className={buttonClass(variant, className)} disabled={disabled || busy} aria-busy={busy || undefined} {...props}>
      {busy && <span aria-hidden="true" className="inline-block size-4 animate-spin rounded-full border-2 border-current border-r-transparent" />}
      {children}
    </button>
  )
}

export function LinkButton({ variant = 'primary', className = '', ...props }) {
  return <Link className={buttonClass(variant, className)} {...props} />
}

const alertTones = {
  error: 'border-error/30 bg-error-tint text-error',
  warning: 'border-warning/30 bg-warning-tint text-warning',
  success: 'border-accent/30 bg-accent-tint text-accent-strong',
  info: 'border-line-strong bg-surface text-ink',
}

export function Alert({ tone = 'info', children, className = '' }) {
  return (
    <div role={tone === 'error' ? 'alert' : 'status'} className={`rounded-lg border px-4 py-3 text-sm ${alertTones[tone]} ${className}`}>
      {children}
    </div>
  )
}

const inputClass =
  'mt-1.5 block min-h-11 w-full rounded-lg border border-line-strong bg-surface px-3 text-base text-ink placeholder:text-muted aria-invalid:border-error sm:text-sm'

export function Field({ id, label, error, hint, as = 'input', className = '', children, ...props }) {
  const Control = as
  const describedBy = [hint && `${id}-hint`, error && `${id}-error`].filter(Boolean).join(' ') || undefined
  return (
    <div className={className}>
      <label htmlFor={id} className="block text-sm font-medium">
        {label}
      </label>
      {hint && (
        <p id={`${id}-hint`} className="mt-0.5 text-xs text-muted">
          {hint}
        </p>
      )}
      <Control id={id} name={id} className={inputClass} aria-invalid={error ? true : undefined} aria-describedby={describedBy} {...props}>
        {children}
      </Control>
      {error && (
        <p id={`${id}-error`} className="mt-1 text-sm text-error">
          {error}
        </p>
      )}
    </div>
  )
}

export function Skeleton({ className = '' }) {
  return <div aria-hidden="true" className={`animate-pulse rounded-lg bg-sunken ${className}`} />
}

export function PageLoading() {
  return (
    <div aria-busy="true" className="space-y-4">
      <span className="sr-only" role="status">
        Loading…
      </span>
      <Skeleton className="h-9 w-48" />
      <Skeleton className="h-32" />
    </div>
  )
}

export function ErrorState({ title = "Couldn't load this", message, onRetry }) {
  return (
    <div role="alert" className="rounded-card border border-line bg-surface px-6 py-10 text-center">
      <p className="font-display text-lg font-semibold">{title}</p>
      <p className="mx-auto mt-1 max-w-md text-sm text-muted">{message}</p>
      {onRetry && (
        <Button variant="secondary" className="mt-5" onClick={onRetry}>
          Try again
        </Button>
      )}
    </div>
  )
}

export function OfflineBanner() {
  const online = useOnline()
  if (online) return null
  return (
    <p role="status" className="bg-ink px-4 py-2 text-center text-sm text-paper">
      You&apos;re offline. Changes can&apos;t be saved until your connection is back.
    </p>
  )
}

export function Card({ title, description, className = '', children, ...props }) {
  return (
    <section className={`min-w-0 rounded-card border border-line bg-surface p-5 sm:p-6 ${className}`} {...props}>
      {title && <h2 className="text-lg">{title}</h2>}
      {description && <p className="mt-1 text-sm text-muted">{description}</p>}
      {children}
    </section>
  )
}
