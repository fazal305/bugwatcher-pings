import { join } from 'node:path'
import { expect } from '@playwright/test'

export const API = 'http://127.0.0.1:8473/api'

// Written by global-setup.js: a browser state already signed into the shared demo account.
export const DEMO_STATE = join(process.cwd(), 'playwright', '.auth', 'demo.json')

export const PUBLIC_PAGES = ['/', '/signin', '/signup', '/faq', '/privacy', '/terms', '/cookies', '/no-such-page']

export async function expectNoHorizontalOverflow(page) {
  const { scroll, client } = await page.evaluate(() => ({
    scroll: document.documentElement.scrollWidth,
    client: document.documentElement.clientWidth,
  }))
  expect(scroll, `${page.url()} is ${scroll - client}px wider than the viewport`).toBe(client)
}

/** Opens the demo project's live feed and waits for the sample cards and the live connection. */
export async function openDemoFeed(page) {
  await page.goto('/app')
  await expect(page).toHaveURL(/\/app\/projects\/[^/]+\/feed$/)
  await expect(page.getByRole('heading', { level: 1, name: 'Live feed' })).toBeVisible()
  await expect(page.getByRole('status').filter({ hasText: /^Live/ })).toBeVisible()
  await expect(page.locator('[data-event-id]').first()).toBeVisible()
}
