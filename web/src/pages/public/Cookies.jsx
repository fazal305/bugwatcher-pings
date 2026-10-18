import { Link } from 'react-router'
import { Email, LegalPage } from '../../components/Legal'
import { useDocumentTitle } from '../../hooks/useDocumentTitle'

export default function Cookies() {
  useDocumentTitle('Cookie Policy')
  return (
    <LegalPage
      title="Cookie Policy"
      updated="19 October 2026"
      intro="BugWatcher Pings sets no cookies. It keeps two small values in your browser's local storage, both needed for the dashboard to work."
    >
      <h2>What is stored in your browser</h2>
      <ul>
        <li>
          <strong>
            <code>bwp_token</code>
          </strong>{' '}
          (local storage): your sign-in token, so you stay signed in between visits. It is sent only to the BugWatcher Pings API, expires
          after seven days, and is removed when you sign out.
        </li>
        <li>
          <strong>
            <code>bwp_last_feed</code>
          </strong>{' '}
          (local storage): the project whose live feed you opened last, so the dashboard can open it again next time.
        </li>
      </ul>
      <p>
        Nothing is stored before you sign in or try the demo. You can remove both values at any time by signing out or clearing this
        site&apos;s data in your browser.
      </p>

      <h2>No tracking</h2>
      <p>
        There are no analytics, advertising or social media scripts, and no cookies from us or from anyone else. The live feed connects to
        Pusher to receive updates; that connection does not set cookies either.
      </p>

      <h2>Why there is no cookie banner</h2>
      <p>
        Consent banners are needed for storage that isn&apos;t strictly necessary, such as analytics or advertising. Everything listed above
        is needed to provide the dashboard you asked to use, and nothing is used to track you, so there is nothing to ask consent for.
      </p>

      <h2>Questions</h2>
      <p>
        See the <Link to="/privacy">Privacy Policy</Link> for what the service stores on its servers, or email <Email />.
      </p>
    </LegalPage>
  )
}
