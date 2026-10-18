import { useDocumentTitle } from '../../hooks/useDocumentTitle'

const SECTIONS = [
  ['Who can use it', 'You must be 16 or older to create an account. We ask you to confirm this when you sign up; we do not ask for or store your date of birth.'],
  ['What we store', 'Your name, email address and a hashed password, plus the projects you create and who belongs to them. Project admins can see the email addresses of their project members.'],
  ['The demo', 'The demo account is shared by everyone who tries it. Don’t put anything private in it, and expect its contents to change or be reset.'],
  ['Your error data', 'Error reports your apps send are stored so they can be grouped and shown to your project members. Don’t send passwords, payment details or other secrets in error reports.'],
  ['Licence', 'BugWatcher Pings is free for personal, educational and other noncommercial use under the PolyForm Noncommercial License 1.0.0. Commercial use needs a paid licence.'],
  ['No warranty', 'The service is provided as is, without any guarantee that it will be available or free of errors.'],
]

export default function Terms() {
  useDocumentTitle('Terms')
  return (
    <div className="mx-auto max-w-2xl px-4 py-12 sm:px-6 sm:py-16">
      <h1 className="text-3xl">Terms</h1>
      <p className="mt-3 text-muted">The short version of the rules for using BugWatcher Pings.</p>
      <div className="mt-8 space-y-6">
        {SECTIONS.map(([title, text]) => (
          <section key={title}>
            <h2 className="text-lg">{title}</h2>
            <p className="mt-1 text-muted">{text}</p>
          </section>
        ))}
      </div>
    </div>
  )
}
