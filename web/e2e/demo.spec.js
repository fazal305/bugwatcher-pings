import { expect, test } from '@playwright/test'
import { DEMO_STATE, expectNoHorizontalOverflow, openDemoFeed } from './helpers'

test('trying the demo signs in and shows labelled sample errors in the live feed', async ({ page }) => {
  await page.goto('/signin')
  await page.getByRole('button', { name: 'Try the demo' }).click()
  await expect(page).toHaveURL(/\/app\/projects$/)
  await expect(page.getByRole('note')).toContainText('shared demo account')

  await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Live feed' }).click()
  await expect(page.getByRole('paragraph').filter({ hasText: 'Demo Storefront (sample data)' })).toBeVisible()
  await expect(page.locator('[data-event-id]')).toHaveCount(13)
  await expect(page.getByText('Sample data').first()).toBeVisible()
})

test.describe('signed in as the demo', () => {
  test.use({ storageState: DEMO_STATE })

  test('a fired test error arrives in the feed live, without a reload', async ({ page }) => {
    await openDemoFeed(page)
    const cards = page.locator('[data-event-id]')
    const before = await cards.count()
    const navigations = []
    page.on('framenavigated', (frame) => frame === page.mainFrame() && navigations.push(frame.url()))

    await page.getByRole('button', { name: 'Fire a test error' }).click()
    await expect(page.getByRole('status').filter({ hasText: 'Test error sent.' })).toBeVisible()

    await expect(cards).toHaveCount(before + 1)
    const newest = cards.first()
    await expect(newest).toContainText("[Test] Cannot read properties of undefined (reading 'after')")
    await expect(newest).not.toContainText('Sample data')
    await expect(newest.getByText('Critical')).toBeVisible()
    await expect(newest).toHaveClass(/feed-enter/)
    expect(navigations).toEqual([])
  })

  test.describe('with reduced motion', () => {
    test.use({ reducedMotion: 'reduce' })

    test('a new card arrives without the entrance animation', async ({ page }) => {
      await openDemoFeed(page)
      const cards = page.locator('[data-event-id]')
      const before = await cards.count()
      await page.getByRole('button', { name: 'Fire a test error' }).click()
      await expect(cards).toHaveCount(before + 1)
      await expect(cards.first()).toContainText('[Test]')
      await expect(cards.first()).not.toHaveClass(/feed-enter/)
    })
  })

  test('a card expands in place to show its details', async ({ page }) => {
    await openDemoFeed(page)
    const card = page.locator('[data-event-id]').filter({ hasText: 'Deadlock detected' }).first()
    const toggle = card.getByRole('button', { name: /about this event$/ })
    await toggle.click()
    await expect(toggle).toHaveAttribute('aria-expanded', 'true')
    await expect(card.getByText('PDOException: SQLSTATE[40P01]: Deadlock detected while updating stock levels')).toBeVisible()
  })

  test('event detail highlights the source and marks the thrown line', async ({ page }) => {
    await openDemoFeed(page)
    await page.locator('[data-event-id]').filter({ hasText: "reading 'toFixed'" }).first().getByRole('link').click()

    await expect(page.getByRole('heading', { level: 1 })).toHaveText("Cannot read properties of undefined (reading 'toFixed')")
    await expect(page).toHaveTitle(/^Cannot read properties of undefined/)
    const source = page.getByRole('region', { name: 'Source around /assets/cart.js line 42' })
    await expect(source.locator('pre[data-language="javascript"] .hljs-keyword').first()).toBeVisible()
    await expect(source.getByText('Error line:')).toBeAttached()
    await expect(source).toContainText('return cart.summary.total.toFixed(2)')
    await expect(page.getByText('[Filtered]').first()).toBeVisible()
    await expect(page.getByText('Sample data')).toBeVisible()
  })

  test.describe('at 375px wide', () => {
    test.use({ viewport: { width: 375, height: 812 } })

    test('no app page scrolls sideways', async ({ page }) => {
      await openDemoFeed(page)
      await expectNoHorizontalOverflow(page)
      const feedUrl = page.url()

      await page.locator('[data-event-id]').filter({ hasText: 'Deadlock detected' }).first().getByRole('link').click()
      await expect(page.getByRole('heading', { name: 'Stack trace' })).toBeVisible()
      await page.getByRole('button', { name: 'Expand all' }).click()
      await expectNoHorizontalOverflow(page)

      await page.goto(feedUrl.replace(/\/feed$/, ''))
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
      await expectNoHorizontalOverflow(page)

      for (const path of ['/app/projects', '/app/projects/new', '/app/no-such-page']) {
        await page.goto(path)
        await expect(page.locator('main h1')).toBeVisible()
        await expectNoHorizontalOverflow(page)
      }
    })
  })
})
