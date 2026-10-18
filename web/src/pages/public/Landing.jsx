import { Link } from 'react-router'
import { useDocumentTitle } from '../../hooks/useDocumentTitle'

const STEPS = [
  ['Ingest', 'Your app sends an error report to the API.'],
  ['Group', 'Workers fingerprint it and fold repeats into one issue.'],
  ['Notify', 'The dashboard updates live and Slack or Discord gets a ping.'],
]

export default function Landing() {
  useDocumentTitle()
  return (
    <div className="mx-auto max-w-5xl px-4 py-16 sm:px-6 sm:py-24">
      <p className="text-sm font-semibold text-accent">In development</p>
      <h1 className="mt-3 max-w-3xl text-4xl leading-tight sm:text-5xl">BugWatcher Pings</h1>
      <p className="mt-5 max-w-2xl text-lg text-muted">
        Error reports from your apps, grouped into issues and shown in a live feed, with a ping to your team in Slack or Discord when something breaks.
      </p>
      <div className="mt-8">
        <Link to="/app" className="inline-flex min-h-11 items-center rounded-lg bg-accent px-5 font-semibold text-on-accent hover:bg-accent-strong">
          Open the dashboard
        </Link>
      </div>

      <section aria-labelledby="how" className="mt-20">
        <h2 id="how" className="text-2xl">
          How it will work
        </h2>
        <ol className="mt-6 grid gap-4 sm:grid-cols-3">
          {STEPS.map(([title, text], i) => (
            <li key={title} className="rounded-card border border-line bg-surface p-5">
              <p className="font-mono text-xs text-muted">0{i + 1}</p>
              <h3 className="mt-2 text-lg">{title}</h3>
              <p className="mt-1 text-sm text-muted">{text}</p>
            </li>
          ))}
        </ol>
      </section>
    </div>
  )
}
