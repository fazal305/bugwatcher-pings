import { Link } from 'react-router'
import { Email, LegalPage } from '../../components/Legal'
import { useDocumentTitle } from '../../hooks/useDocumentTitle'

export default function Privacy() {
  useDocumentTitle('Privacy Policy')
  return (
    <LegalPage
      title="Privacy Policy"
      updated="19 October 2026"
      intro="BugWatcher Pings is a small error monitoring service run by Fazal Abbas. This page explains what it stores, who else handles it, how long it is kept and how to have it removed."
    >
      <h2>What we collect</h2>
      <ul>
        <li>
          <strong>Your account:</strong> your name, your email address and a hash of your password (never the password itself). We also
          store the projects you create or join and your role in each.
        </li>
        <li>
          <strong>Error reports your apps send:</strong> the message, severity, environment, time, release, the exception type and stack
          frames with a few lines of surrounding source code, an optional user ID, and optional request context (method, URL, headers,
          query string and body). Before a report is stored, the value of any field whose name contains <code>password</code>,{' '}
          <code>token</code>, <code>secret</code>, <code>authorization</code>, <code>cookie</code>, <code>api_key</code>,{' '}
          <code>apikey</code>, <code>x-api-key</code>, <code>credit_card</code> or <code>card_number</code>, or is the word{' '}
          <code>cvv</code> or <code>ssn</code>, is replaced with <code>[Filtered]</code>, at any depth and in the URL&apos;s query string.
          Anything else your app puts in a report is stored as sent.
        </li>
        <li>
          <strong>Alert settings and deliveries:</strong> the Slack, Discord or webhook URLs you add (encrypted in the database), their
          filters, and a log of each alert sent: when, whether it worked and the HTTP status. The log never holds the receiver&apos;s reply.
        </li>
        <li>
          <strong>Invitations:</strong> the email address and role a project admin invites someone with, who sent the invitation, and when
          it expires or was accepted. Invitations are deleted when they are revoked or the project is deleted.
        </li>
      </ul>
      <p>We use this data only to run BugWatcher Pings for you and your project members. We do not sell it or use it for advertising.</p>

      <h2>What we don&apos;t do</h2>
      <p>
        There is no analytics, no session replay, no advertising and no tracking script on this site. The dashboard does not report its
        own visitors&apos; behaviour anywhere.
      </p>

      <h2>IP addresses</h2>
      <p>
        Your IP address is used to rate limit sign-in, sign-up, the demo and error reports, which protects the service from abuse. It is
        held only in short-lived Redis counters that expire with their limit window (from one second up to one day) and is not stored in
        the database or attached to your account. Our hosting providers may keep standard access logs under their own policies.
      </p>

      <h2>How long we keep it</h2>
      <ul>
        <li>
          Individual error events and alert delivery records are deleted automatically after 30 days. The operator can change this period
          (the <code>PINGS_RETENTION_DAYS</code> setting); this page will say so if it does.
        </li>
        <li>
          Issues (the grouped summary of an error: its title, where it happened, counts and first and last seen times) are kept while the
          project exists.
        </li>
        <li>Your account, projects and alert settings are kept until you ask us to delete them.</li>
        <li>
          The shared demo project is reset to its sample data once a day; at each reset, events sent from the demo that are more than 24
          hours old are removed.
        </li>
      </ul>

      <h2>Who else processes it</h2>
      <ul>
        <li>
          <strong>Render</strong> runs the API server and its background workers.
        </li>
        <li>
          <strong>Vercel</strong> serves this website.
        </li>
        <li>
          <strong>Supabase</strong> hosts the PostgreSQL database where accounts, projects, issues and events are stored.
        </li>
        <li>
          <strong>Upstash</strong> hosts Redis, which holds error reports briefly while they wait to be processed (or while the database is
          unreachable), the rate-limit counters and short-lived caches.
        </li>
        <li>
          <strong>Pusher</strong> delivers live feed updates to your browser. It receives what a feed card shows (message, severity,
          environment, issue title, location and times), never request data, user IDs or stack traces.
        </li>
        <li>
          <strong>Slack, Discord or your own endpoint</strong>, only if a project admin sets up alerts: each alert sends the error&apos;s
          message, type, location, environment, time, event count, the project&apos;s name and a link to the event.
        </li>
      </ul>

      <h2>Your browser</h2>
      <p>
        The site sets no cookies. Signing in stores a sign-in token in your browser&apos;s local storage; see the{' '}
        <Link to="/cookies">Cookie Policy</Link> for the details.
      </p>

      <h2>Deleting your data</h2>
      <p>
        There is no delete button yet. To have your account and the projects only you belong to deleted, with all their issues, events and
        alert settings, email <Email /> from the address you signed up with. We will confirm once it is done.
      </p>

      <h2>Age</h2>
      <p>BugWatcher Pings is for people aged 16 or older. We do not knowingly collect data from anyone younger.</p>

      <h2>Changes and contact</h2>
      <p>
        If this policy changes, we will update this page and the date above. Questions or requests about your data: <Email />. See also the{' '}
        <Link to="/terms">Terms of Service</Link>.
      </p>
    </LegalPage>
  )
}
