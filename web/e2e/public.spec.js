import { expect, test } from '@playwright/test'
import { expectNoHorizontalOverflow, PUBLIC_PAGES } from './helpers'

test('landing page explains the product and offers the demo', async ({ page }) => {
  await page.goto('/')
  await expect(page).toHaveTitle('BugWatcher Pings — real-time error monitoring and alerts')
  await expect(page.getByRole('heading', { level: 1, name: 'BugWatcher Pings' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Try the demo' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Create an account' })).toBeVisible()
  await expect(page.locator('meta[property="og:image"]')).toHaveAttribute('content', 'https://bugwatcher-pings.vercel.app/og-image.png')
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', 'https://bugwatcher-pings.vercel.app/')
})

for (const [path, link, title, heading] of [
  ['/privacy', 'Privacy', 'Privacy Policy · BugWatcher Pings', 'Privacy Policy'],
  ['/terms', 'Terms', 'Terms of Service · BugWatcher Pings', 'Terms of Service'],
  ['/cookies', 'Cookies', 'Cookie Policy · BugWatcher Pings', 'Cookie Policy'],
  ['/faq', 'FAQ', 'FAQ · BugWatcher Pings', 'Frequently asked questions'],
]) {
  test(`${path} is linked from the footer and has its own title`, async ({ page }) => {
    await page.goto('/')
    await page.getByRole('navigation', { name: 'Legal and help' }).getByRole('link', { name: link }).click()
    await expect(page).toHaveURL(path)
    await expect(page).toHaveTitle(title)
    await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible()
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', `https://bugwatcher-pings.vercel.app${path}`)
    await expect(page.getByText('© 2026 Fazal Abbas', { exact: true })).toBeVisible()
  })
}

test('legal pages state the facts users rely on', async ({ page }) => {
  await page.goto('/privacy')
  await expect(page.getByText('deleted automatically after 30 days')).toBeVisible()
  for (const processor of ['Render', 'Vercel', 'Supabase', 'Upstash', 'Pusher']) {
    await expect(page.getByRole('strong').filter({ hasText: new RegExp(`^${processor}$`) })).toBeVisible()
  }
  await page.goto('/terms')
  await expect(page.getByText('You must be 16 or older')).toBeVisible()
  await expect(page.getByText(/99\.99% availability .* is a design target, not a promise/)).toBeVisible()
  await page.goto('/cookies')
  await expect(page.getByRole('heading', { name: 'Why there is no cookie banner' })).toBeVisible()
})

test('unknown pages show the 404 page', async ({ page }) => {
  await page.goto('/no-such-page')
  await expect(page.getByRole('heading', { level: 1, name: 'Page not found' })).toBeVisible()
  await expect(page).toHaveTitle('Page not found · BugWatcher Pings')
})

test('robots.txt, sitemap.xml and the social image are served as files', async ({ request }) => {
  const robots = await request.get('/robots.txt')
  expect(await robots.text()).toContain('Sitemap: https://bugwatcher-pings.vercel.app/sitemap.xml')
  const sitemap = await request.get('/sitemap.xml')
  expect(await sitemap.text()).toContain('<loc>https://bugwatcher-pings.vercel.app/privacy</loc>')
  const image = await request.get('/og-image.png')
  expect(image.headers()['content-type']).toBe('image/png')
})

test('sign in shows field errors and a wrong-password message', async ({ page }) => {
  await page.goto('/signin')
  await page.getByRole('button', { name: 'Sign in', exact: true }).click()
  await expect(page.getByText('The email field is required.')).toBeVisible()
  await expect(page.getByLabel('Email')).toHaveAttribute('aria-invalid', 'true')

  await page.getByLabel('Email').fill(`nobody-${Date.now()}@example.com`)
  await page.getByLabel('Password').fill('not-the-password')
  await page.getByRole('button', { name: 'Sign in', exact: true }).click()
  await expect(page.getByRole('alert')).toHaveText('The email or password is incorrect.')
})

test('the app redirects signed-out visitors to sign in', async ({ page }) => {
  await page.goto('/app/projects')
  await expect(page).toHaveURL(/\/signin$/)
})

test.describe('at 375px wide', () => {
  test.use({ viewport: { width: 375, height: 812 } })

  for (const path of PUBLIC_PAGES) {
    test(`${path} does not scroll sideways`, async ({ page }) => {
      await page.goto(path)
      await expect(page.locator('main h1')).toBeVisible()
      await expectNoHorizontalOverflow(page)
    })
  }
})
