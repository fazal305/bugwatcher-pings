import { expect, test } from '@playwright/test'

// A tab opened before a deploy asks for chunks the new deploy no longer has. Simulated by answering the FeedIndex chunk
// with a 404, as Vercel now does for a missing /assets file.
const FEED_INDEX_CHUNK = /\/assets\/FeedIndex-[^/]+\.js$/
const USER = { id: 1, name: 'Test User', email: 'test@example.com', is_demo: false }

async function mockSignedInApi(page) {
  await page.addInitScript(() => localStorage.setItem('bwp_token', 'test-token'))
  await page.route(
    (url) => url.pathname.endsWith('/api/me'),
    (route) => route.fulfill({ json: { user: USER } }),
  )
  await page.route(
    (url) => url.pathname.endsWith('/api/projects'),
    (route) => route.fulfill({ json: { data: [] } }),
  )
}

/** Counts full page loads; same-document (history API) navigations don't fetch a document. */
function countDocumentLoads(page) {
  const loads = { count: 0 }
  page.on('request', (request) => {
    if (request.isNavigationRequest() && request.frame() === page.mainFrame()) loads.count++
  })
  return loads
}

test('a missing chunk reloads the page once and the feed then loads', async ({ page }) => {
  await mockSignedInApi(page)
  const loads = countDocumentLoads(page)
  // Every request for the chunk fails in the first document (its module map keeps the failure); after the reload the
  // new build's chunk is there.
  await page.route(FEED_INDEX_CHUNK, (route) => (loads.count <= 1 ? route.fulfill({ status: 404, body: 'Not found' }) : route.continue()))

  await page.goto('/app')
  await expect(page.getByRole('heading', { level: 1, name: 'Live feed' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'No projects yet' })).toBeVisible()
  expect(loads.count).toBe(2)
  await expect(page.getByText('Unexpected Application Error')).toHaveCount(0)
  await expect(page.getByText('Something went wrong')).toHaveCount(0)
})

test('a chunk that keeps failing shows the error page without a reload loop', async ({ page }) => {
  await mockSignedInApi(page)
  const loads = countDocumentLoads(page)
  await page.route(FEED_INDEX_CHUNK, (route) => route.fulfill({ status: 404, body: 'Not found' }))

  await page.goto('/app')
  const heading = page.getByRole('heading', { level: 1, name: 'Something went wrong' })
  await expect(heading).toBeVisible()
  await expect(heading).toBeFocused()
  await expect(page.getByRole('button', { name: 'Reload' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Back to the home page' })).toHaveAttribute('href', '/')
  await expect(page.getByText('Unexpected Application Error')).toHaveCount(0)
  await expect(page.getByText(/dynamically imported module/)).toHaveCount(0)

  // One automatic reload, then no more.
  await page.waitForTimeout(3000)
  expect(loads.count).toBe(2)
  await expect(heading).toBeVisible()
})
