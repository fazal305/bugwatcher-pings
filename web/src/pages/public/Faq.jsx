import { Link } from 'react-router'
import { LegalPage } from '../../components/Legal'
import { useDocumentTitle } from '../../hooks/useDocumentTitle'
import { API_URL } from '../../lib/api'

const QUESTIONS = [
  {
    q: 'How do I send a ping?',
    a: (
      <>
        <p>
          Create a project to get its public key (it starts with <code>bwp_pk_</code>), then POST a JSON error report to{' '}
          <code>{API_URL}/v1/ping</code> with the key in the <code>X-BugWatcher-Key</code> header:
        </p>
        <pre className="overflow-x-auto rounded-lg bg-sunken p-3 font-mono text-xs leading-relaxed">
          <code>{`curl -X POST ${API_URL}/v1/ping \\
  -H "Content-Type: application/json" \\
  -H "X-BugWatcher-Key: bwp_pk_..." \\
  -d '{"message": "Cart total is undefined", "level": "error",
       "environment": "production", "timestamp": 1760000000000}'`}</code>
        </pre>
        <p>
          <code>level</code> is fatal, error, warning or info, and <code>environment</code> is production, staging or development. Stack
          frames, request details, a user ID and the release are optional. For real apps, the dependency-free JavaScript (browser) and PHP
          SDKs in the repository report uncaught errors for you. The feed of a new project shows a ready-made command with your key.
        </p>
      </>
    ),
  },
  {
    q: 'What gets masked?',
    a: (
      <p>
        Before a report is queued, the value of every field whose name contains password, token, secret, authorization, cookie, api_key,
        apikey, x-api-key, credit_card or card_number, or is the word cvv or ssn, is replaced with <code>[Filtered]</code>. Matching ignores
        case and separators, so <code>user_password</code>, <code>X-Api-Key</code> and <code>accessToken</code> are all caught, at any depth
        and in the URL&apos;s query string. Anything else, such as an email address in a message, is stored as sent, so keep personal data
        out of your reports.
      </p>
    ),
  },
  {
    q: 'Why is the first load slow?',
    a: (
      <p>
        The API runs on a free hosting tier that goes to sleep after about 15 minutes without traffic. The first request after that wakes
        it up, which can take up to about 50 seconds; the dashboard says so while it waits. After that, pages load normally and reports
        reach the live feed within a moment.
      </p>
    ),
  },
  {
    q: 'How do alerts work?',
    a: (
      <p>
        A project admin can add Slack, Discord or signed JSON webhook endpoints under the project&apos;s settings, each with a minimum
        severity and a list of environments. When a report matches, an alert is sent with the message, location and a link to the event.
        Repeats of the same issue are held back for a cooldown (five minutes by default), but a new issue or a regression of a resolved
        one is always sent. Each endpoint has a Send test button and a log of recent deliveries.
      </p>
    ),
  },
  {
    q: 'What can I do in the demo?',
    a: (
      <>
        <p>
          &ldquo;Try the demo&rdquo; signs you into a shared account with a project full of sample errors, marked &ldquo;Sample data&rdquo;.
          You can browse the live feed, open events to see stack traces and masked request data, resolve and reopen issues, and press
          &ldquo;Fire a test error&rdquo; to send a real report through the same pipeline your apps would use and watch it arrive live. Test
          errors start with <code>[Test]</code>, and you can send five a minute.
        </p>
        <p>
          Because everyone shares it, the demo can&apos;t invite or remove members, rotate the project key or set up alerts. It is reset to its
          sample data once a day. Create a free account to try those parts with your own project.
        </p>
      </>
    ),
  },
  {
    q: 'How long are errors kept?',
    a: (
      <p>
        Individual events are deleted after 30 days; the grouped issues stay. The <Link to="/privacy">Privacy Policy</Link> has the details.
      </p>
    ),
  },
  {
    q: 'Can I use it for my business?',
    a: (
      <p>
        The hosted service is a free demonstration with no uptime guarantee (see the <Link to="/terms">Terms</Link>). The source code is free
        for noncommercial use under the PolyForm Noncommercial License 1.0.0; commercial use needs a paid license.
      </p>
    ),
  },
]

export default function Faq() {
  useDocumentTitle('FAQ')
  return (
    <LegalPage title="Frequently asked questions">
      {QUESTIONS.map(({ q, a }) => (
        <section key={q}>
          <h2>{q}</h2>
          <div className="mt-2 space-y-3">{a}</div>
        </section>
      ))}
    </LegalPage>
  )
}
