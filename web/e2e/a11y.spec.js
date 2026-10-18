import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { DEMO_STATE, openDemoFeed, PUBLIC_PAGES } from './helpers'

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']

async function expectNoViolations(page) {
  const { violations } = await new AxeBuilder({ page }).withTags(TAGS).analyze()
  expect(violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([])
}

for (const scheme of ['light', 'dark']) {
  test.describe(`${scheme} theme`, () => {
    test.use({ colorScheme: scheme })

    for (const path of PUBLIC_PAGES) {
      test(`${path} has no axe violations`, async ({ page }) => {
        await page.goto(path)
        await expect(page.locator('main h1')).toBeVisible()
        await expectNoViolations(page)
      })
    }

    test.describe('signed in as the demo', () => {
      test.use({ storageState: DEMO_STATE })

      test('the feed, an event and the project pages have no axe violations', async ({ page }) => {
        await openDemoFeed(page)
        await page.locator('[data-event-id]').first().getByRole('button', { name: 'More about this event' }).click()
        await expectNoViolations(page)
        const feedUrl = page.url()

        await page.locator('[data-event-id]').filter({ hasText: "reading 'toFixed'" }).first().getByRole('link').click()
        await expect(page.locator('pre[data-language="javascript"] .hljs-keyword').first()).toBeVisible()
        await expectNoViolations(page)

        await page.goto(feedUrl.replace(/\/feed$/, ''))
        await expect(page.getByRole('heading', { name: 'Alerts', exact: true })).toBeVisible()
        await expectNoViolations(page)

        await page.goto('/app/projects')
        await expect(page.locator('main h1')).toBeVisible()
        await expectNoViolations(page)
      })
    })
  })
}
