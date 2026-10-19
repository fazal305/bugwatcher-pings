import { useEffect, useRef, useState } from 'react'
import { Link, useRouteError } from 'react-router'
import { Wordmark } from '../components/Logo'
import { Button, LinkButton } from '../components/ui'
import { useDocumentTitle } from '../hooks/useDocumentTitle'
import { isChunkLoadError, isReloading, reloadOnce } from '../lib/chunkReload'

/** Shown instead of react-router's developer error screen. A chunk missing after a deploy reloads the page once first. */
export default function RouteError() {
  const error = useRouteError()
  const [reloading] = useState(() => (isChunkLoadError(error) && reloadOnce()) || isReloading())

  if (reloading) return null
  return <ErrorPage />
}

function ErrorPage() {
  useDocumentTitle('Something went wrong')
  const heading = useRef(null)

  useEffect(() => {
    heading.current?.focus()
  }, [])

  return (
    <main id="main" className="mx-auto max-w-lg px-4 py-24 text-center sm:px-6">
      <Link to="/" reloadDocument aria-label="BugWatcher Pings home" className="inline-block">
        <Wordmark />
      </Link>
      <h1 ref={heading} tabIndex={-1} className="mt-10 text-3xl outline-none">
        Something went wrong
      </h1>
      <p className="mt-3 text-muted">This page couldn&apos;t be shown. If a new version was just released, reloading usually fixes it.</p>
      <div className="mt-6 flex flex-wrap justify-center gap-3">
        <Button onClick={() => window.location.reload()}>Reload</Button>
        <LinkButton to="/" reloadDocument variant="secondary">
          Back to the home page
        </LinkButton>
      </div>
    </main>
  )
}
