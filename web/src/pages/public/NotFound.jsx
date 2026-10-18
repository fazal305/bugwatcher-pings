import { Link } from 'react-router'
import { useDocumentTitle } from '../../hooks/useDocumentTitle'

export default function NotFound() {
  useDocumentTitle('Page not found')
  return (
    <div className="mx-auto max-w-lg px-4 py-24 text-center sm:px-6">
      <p className="font-mono text-sm text-muted">404</p>
      <h1 className="mt-2 text-3xl">Page not found</h1>
      <p className="mt-3 text-muted">That address doesn&apos;t match any page.</p>
      <Link to="/" className="mt-6 inline-block font-semibold text-accent underline-offset-4 hover:underline">
        Back to the home page
      </Link>
    </div>
  )
}
