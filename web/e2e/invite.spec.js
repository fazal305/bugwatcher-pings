import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { API, DEMO_STATE, expectNoHorizontalOverflow } from './helpers'

const PASSWORD = 'invite-flow-password'

async function register(request, name, email) {
  const res = await request.post(`${API}/auth/register`, {
    headers: { Accept: 'application/json' },
    data: { name, email, password: PASSWORD, age_confirmed: true },
  })
  expect(res.status(), await res.text()).toBe(201)
  return (await res.json()).token
}

test('an admin invites someone by link, and they sign up with that email and join', async ({ browser, request }) => {
  const stamp = Date.now()
  const inviteeEmail = `invitee-${stamp}@example.com`
  const adminToken = await register(request, 'Invite Admin', `invite-admin-${stamp}@example.com`)
  const created = await request.post(`${API}/projects`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${adminToken}` },
    data: { name: 'Invite Flow' },
  })
  const { slug } = (await created.json()).data

  const adminContext = await browser.newContext({
    storageState: { cookies: [], origins: [{ origin: 'http://localhost:5473', localStorage: [{ name: 'bwp_token', value: adminToken }] }] },
  })
  await adminContext.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: 'http://localhost:5473' })
  const admin = await adminContext.newPage()
  await admin.goto(`/app/projects/${slug}`)
  await expect(admin.getByText('No pending invitations.')).toBeVisible()

  // Inline validation errors from the server.
  await admin.getByRole('button', { name: 'Create invite link' }).click()
  await expect(admin.getByLabel('Email', { exact: true })).toHaveAttribute('aria-invalid', 'true')

  await admin.getByLabel('Email', { exact: true }).fill(inviteeEmail)
  await admin.getByRole('button', { name: 'Create invite link' }).click()
  await expect(admin.getByText(`Invite link for ${inviteeEmail}`)).toBeVisible()
  await expect(admin.getByText('Copy this link and send it to them. It works once and expires in 7 days.')).toBeVisible()
  const copyButton = admin.getByRole('button', { name: `Copy invite link for ${inviteeEmail}` })
  await expect(copyButton).toBeFocused()
  await copyButton.click()
  await expect(admin.getByText('Copied to clipboard.')).toBeVisible()
  const link = await admin.evaluate(() => navigator.clipboard.readText())
  expect(link).toMatch(/^http:\/\/localhost:5473\/invite\/[A-Za-z0-9]{48}$/)
  await expect(admin.getByRole('listitem').filter({ hasText: inviteeEmail })).toContainText('Developer')

  // The invitee opens the link signed out, creates an account and comes back to accept.
  const inviteeContext = await browser.newContext()
  const invitee = await inviteeContext.newPage()
  await invitee.goto(link)
  await expect(invitee.getByRole('heading', { level: 1, name: 'Join Invite Flow' })).toBeFocused()
  await expect(invitee.getByText(inviteeEmail)).toHaveCount(0)
  await invitee.getByRole('link', { name: 'Create an account' }).click()
  await expect(invitee).toHaveURL(/\/signup$/)
  await invitee.getByLabel('Name').fill('Invitee')
  await invitee.getByLabel('Email').fill(inviteeEmail)
  await invitee.getByLabel('Password').fill(PASSWORD)
  await invitee.getByLabel(/16 or older/).check()
  await invitee.getByRole('button', { name: 'Create account' }).click()

  await expect(invitee).toHaveURL(new URL(link).pathname)
  await expect(invitee.getByText(`Signed in as ${inviteeEmail}.`)).toBeVisible()
  await invitee.getByRole('button', { name: 'Accept invitation' }).click()
  await expect(invitee).toHaveURL(`/app/projects/${slug}/feed`)

  // The link was single use.
  await invitee.goto(link)
  await expect(invitee.getByRole('alert')).toHaveText('This invitation link is invalid or has expired.')

  await admin.reload()
  const members = admin.getByRole('heading', { name: 'Members' }).locator('..')
  await expect(members.getByText(inviteeEmail)).toBeVisible()
  await expect(admin.getByText('No pending invitations.')).toBeVisible()

  await adminContext.close()
  await inviteeContext.close()
})

test('an admin can revoke a pending invitation, and its link stops working', async ({ browser, request }) => {
  const stamp = Date.now()
  const adminToken = await register(request, 'Revoke Admin', `revoke-admin-${stamp}@example.com`)
  const headers = { Accept: 'application/json', Authorization: `Bearer ${adminToken}` }
  const { slug } = (await (await request.post(`${API}/projects`, { headers, data: { name: 'Revoke Flow' } })).json()).data
  const invite = await (await request.post(`${API}/projects/${slug}/invitations`, { headers, data: { email: `gone-${stamp}@example.com`, role: 'admin' } })).json()

  const context = await browser.newContext({
    storageState: { cookies: [], origins: [{ origin: 'http://localhost:5473', localStorage: [{ name: 'bwp_token', value: adminToken }] }] },
  })
  const page = await context.newPage()
  await page.goto(`/app/projects/${slug}`)
  await page.getByRole('button', { name: `Revoke invitation for gone-${stamp}@example.com` }).click()
  await expect(page.getByRole('button', { name: 'Cancel' })).toBeFocused()
  await page.getByRole('group', { name: /Confirm revoking/ }).getByRole('button', { name: 'Revoke' }).click()
  await expect(page.getByText('No pending invitations.')).toBeVisible()

  await page.goto(new URL(invite.invite_url).pathname)
  await expect(page.getByRole('heading', { level: 1, name: "This invitation can't be used" })).toBeFocused()
  await context.close()
})

test('a bad link shows the invalid state', async ({ page }) => {
  await page.goto('/invite/not-a-real-token')
  await expect(page.getByRole('heading', { level: 1, name: "This invitation can't be used" })).toBeVisible()
  await expect(page.getByRole('alert')).toHaveText('This invitation link is invalid or has expired.')
})

test.describe('the demo account', () => {
  test.use({ storageState: DEMO_STATE })

  test('does not see the invite form', async ({ page }) => {
    await page.goto('/app/projects')
    await page.getByRole('link', { name: /Demo Storefront/ }).first().click()
    await expect(page.getByRole('heading', { name: 'Members' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Create invite link' })).toHaveCount(0)
    await expect(page.getByRole('heading', { name: 'Pending invitations' })).toHaveCount(0)
  })
})

test.describe('at 375px wide', () => {
  test.use({ viewport: { width: 375, height: 812 } })

  test('the invite page and the members section do not scroll sideways, and the invite page passes axe', async ({ browser, request }) => {
    const stamp = Date.now()
    const adminToken = await register(request, 'Narrow Admin', `narrow-admin-${stamp}@example.com`)
    const headers = { Accept: 'application/json', Authorization: `Bearer ${adminToken}` }
    const { slug } = (await (await request.post(`${API}/projects`, { headers, data: { name: 'Narrow Flow' } })).json()).data

    const context = await browser.newContext({
      viewport: { width: 375, height: 812 },
      storageState: { cookies: [], origins: [{ origin: 'http://localhost:5473', localStorage: [{ name: 'bwp_token', value: adminToken }] }] },
    })
    const page = await context.newPage()
    await page.goto(`/app/projects/${slug}`)
    await page.getByLabel('Email', { exact: true }).fill(`a-rather-long-address-for-a-narrow-screen-${stamp}@example.com`)
    await page.getByRole('button', { name: 'Create invite link' }).click()
    const link = await page.locator('code').filter({ hasText: '/invite/' }).textContent()
    await expect(page.getByRole('heading', { name: 'Pending invitations' })).toBeVisible()
    await expect(page.getByRole('button', { name: /^Revoke invitation for/ })).toBeVisible()
    await expectNoHorizontalOverflow(page)
    await context.close()

    for (const scheme of ['light', 'dark']) {
      const guest = await browser.newContext({ viewport: { width: 375, height: 812 }, colorScheme: scheme })
      const invitePage = await guest.newPage()
      await invitePage.goto(new URL(link).pathname)
      await expect(invitePage.getByRole('link', { name: 'Sign in to accept' })).toBeVisible()
      await expectNoHorizontalOverflow(invitePage)
      const { violations } = await new AxeBuilder({ page: invitePage }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze()
      expect(violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([])

      await invitePage.goto('/invite/not-a-real-token')
      await expect(invitePage.getByRole('alert')).toBeVisible()
      await expectNoHorizontalOverflow(invitePage)
      await guest.close()
    }
  })
})
