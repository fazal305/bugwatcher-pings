import { Link } from 'react-router'
import { Email, LegalPage } from '../../components/Legal'
import { useDocumentTitle } from '../../hooks/useDocumentTitle'

export default function Terms() {
  useDocumentTitle('Terms of Service')
  return (
    <LegalPage
      title="Terms of Service"
      updated="19 October 2026"
      intro="These terms cover using BugWatcher Pings, the website and API run by Fazal Abbas. By creating an account or using the demo you agree to them."
    >
      <h2>Who can use it</h2>
      <p>
        You must be 16 or older to create an account. We ask you to confirm this when you sign up; we do not ask for or store your date of
        birth.
      </p>

      <h2>Acceptable use</h2>
      <ul>
        <li>Use BugWatcher Pings to monitor errors from software you run or are allowed to monitor.</li>
        <li>
          Don&apos;t try to get around rate limits, reach projects you are not a member of, or overload the service (for example with load
          tests against the hosted API).
        </li>
        <li>Don&apos;t use alerts or webhooks to send requests to systems you have no permission to contact.</li>
        <li>Don&apos;t send content that is illegal or that you have no right to share.</li>
        <li>
          The demo account is shared by everyone who tries it. Don&apos;t put anything private in it, and expect it to be reset to sample
          data every day.
        </li>
      </ul>
      <p>We may suspend accounts or projects that break these rules or put the service at risk.</p>

      <h2>Personal data in your error reports</h2>
      <p>
        BugWatcher Pings masks values under common secret field names before storing a report (the list is in the{' '}
        <Link to="/privacy">Privacy Policy</Link>), but it cannot recognise personal data in general. You are responsible for configuring
        your apps so that error reports don&apos;t carry personal data (names, email addresses, addresses, payment or health details) beyond
        what masking removes, and for having a lawful basis for anything you do send.
      </p>

      <h2>Your content</h2>
      <p>
        You own the data your apps send and the projects you create. You give us permission to store, process and display it to you and your
        project members, and to send it to the alert destinations you set up, only as needed to run the service.
      </p>
      <p>
        The website, its design and its text are © 2026 Fazal Abbas. The source code is licensed separately: free for noncommercial use
        under the PolyForm Noncommercial License 1.0.0, while commercial use needs a paid license (contact <Email />
        ).
      </p>

      <h2>No guarantee of service</h2>
      <p>
        BugWatcher Pings is a free demonstration service, provided as is, without warranties of any kind and without a service level
        agreement. It runs on free hosting tiers, may be slow to wake up after a quiet period, and may be unavailable, lose data or change
        without notice. The 99.99% availability in the project&apos;s design requirements is a design target, not a promise. Don&apos;t
        rely on it as your only way of finding out about errors.
      </p>
      <p>To the extent the law allows, we are not liable for any loss caused by using the service or by it being unavailable.</p>

      <h2>Ending your use</h2>
      <p>
        You can stop using BugWatcher Pings at any time and ask for your account to be deleted by emailing <Email />. We may close the service
        or remove inactive accounts after giving notice on this site.
      </p>

      <h2>Changes and contact</h2>
      <p>
        If these terms change, we will update this page and the date above. Questions: <Email />.
      </p>
    </LegalPage>
  )
}
