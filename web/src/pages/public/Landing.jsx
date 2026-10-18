import { DemoButton } from '../../components/DemoButton'
import { LinkButton } from '../../components/ui'
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
      <p className="text-sm font-semibold text-accent">Real-time error monitoring</p>
      <h1 className="mt-3 max-w-3xl text-4xl leading-tight sm:text-5xl">BugWatcher Pings</h1>
      <p className="mt-5 max-w-2xl text-lg text-muted">
        Error reports from your apps, grouped into issues and shown in a live feed, with a ping to your team in Slack or Discord when something breaks.
      </p>
      <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:items-start">
        <LinkButton to="/signup" className="px-5">
          Create an account
        </LinkButton>
        <DemoButton />
      </div>

      <section aria-labelledby="how" className="mt-20">
        <h2 id="how" className="text-2xl">
          How it works
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
