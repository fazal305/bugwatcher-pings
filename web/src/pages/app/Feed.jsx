import { useDocumentTitle } from '../../hooks/useDocumentTitle'

const SEVERITIES = ['fatal', 'error', 'warning', 'info']

const BADGE = {
  fatal: 'bg-fatal-tint text-fatal',
  error: 'bg-error-tint text-error',
  warning: 'bg-warning-tint text-warning',
  info: 'bg-info-tint text-info',
}

export default function Feed() {
  useDocumentTitle('Live feed')
  return (
    <>
      <h1 className="text-3xl">Live feed</h1>
      <p className="mt-2 text-muted">New errors will appear here as they arrive.</p>

      <div className="mt-8 rounded-card border border-dashed border-line-strong bg-surface px-6 py-12 text-center">
        <h2 className="text-lg">No errors yet</h2>
        <p className="mx-auto mt-1 max-w-md text-sm text-muted">Ingestion isn&apos;t connected yet. Each issue will carry one of these severity levels:</p>
        <ul className="mt-4 flex flex-wrap justify-center gap-2" aria-label="Severity levels">
          {SEVERITIES.map((level) => (
            <li key={level} className={`rounded-full px-2.5 py-0.5 font-mono text-xs font-medium ${BADGE[level]}`}>
              {level}
            </li>
          ))}
        </ul>
      </div>
    </>
  )
}
