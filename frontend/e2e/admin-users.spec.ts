import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test'

import { latestLinkTo } from './mailpit.ts'

// Created by `make e2e-seed`.
const owner = 'e2e-owner@example.test'
const ownerPassword = process.env.E2E_PASSWORD ?? 'e2e-password-123'
const invitedPassword = 'invited-password-1'

// Every scenario invites its own user (a unique email per run, so reruns on the same database
// never meet "invitation pending", "already exists" or the reset rate limiter), which keeps the
// scenarios independent and lets them run in parallel; no serial describe is needed.
// Each scenario signs in the owner, invites and accepts through the dev server, which compiles on
// first use; 30 seconds is not enough when they run in parallel.
test.setTimeout(90_000)

const uniqueEmail = (label: string) => `e2e-${label}-${String(Date.now())}@example.test`

async function signIn(page: Page, email: string, secret: string) {
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password', { exact: true }).fill(secret)
  await page.getByRole('button', { name: 'Sign in' }).click()
}

/** A fresh browser context (own cookies), so the owner and the invited user never share a session. */
const contexts: BrowserContext[] = []

async function newPage(browser: Browser): Promise<Page> {
  const context = await browser.newContext()
  contexts.push(context)
  return context.newPage()
}

test.afterEach(async () => {
  await Promise.all(contexts.splice(0).map((context) => context.close()))
})

async function signInAsOwner(browser: Browser): Promise<Page> {
  const page = await newPage(browser)
  await page.goto('/login')
  await signIn(page, owner, ownerPassword)
  await expect(page).toHaveURL('/admin')
  return page
}

async function invite(page: Page, email: string) {
  await page.getByRole('button', { name: 'Invite user' }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByLabel('Email').fill(email)
  await dialog.getByRole('checkbox', { name: 'Solo player' }).click()
  await dialog.getByRole('button', { name: 'Send invitation' }).click()
  await expect(page.getByRole('status')).toHaveText(`Invitation sent to ${email}`)
}

async function acceptInvitation(page: Page, email: string) {
  await page.goto(await latestLinkTo(email, '/accept-invitation'))
  await expect(page.getByText(email)).toBeVisible()
  // The token is gone from the address bar once the page has read it.
  expect(page.url()).not.toContain('token')
  await page.getByLabel('New password', { exact: true }).fill(invitedPassword)
  await page.getByLabel('Confirm password').fill(invitedPassword)
  await page.getByRole('button', { name: 'Create account' }).click()
  await expect(page).toHaveURL('/play')
}

/** Invites `email` as the owner and accepts the invitation; the user ends up with a password. */
async function createUser(browser: Browser, email: string): Promise<Page> {
  const ownerPage = await signInAsOwner(browser)
  await invite(ownerPage, email)
  await acceptInvitation(await newPage(browser), email)
  return ownerPage
}

test('an invited user accepts the invitation and lands signed in', async ({ browser }) => {
  const email = uniqueEmail('invite')
  const ownerPage = await signInAsOwner(browser)
  await invite(ownerPage, email)

  const page = await newPage(browser)
  await acceptInvitation(page, email)

  await expect(page).toHaveURL('/play')
  await expect(page.getByText(email)).toBeVisible()
})

test('a disabled user cannot sign in', async ({ browser }) => {
  const email = uniqueEmail('disabled')
  const ownerPage = await createUser(browser, email)

  await ownerPage.reload()
  const row = ownerPage.getByRole('row', { name: new RegExp(email) })
  await row.getByRole('button', { name: 'Disable' }).click()
  await ownerPage.getByRole('dialog').getByRole('button', { name: 'Disable' }).click()
  await expect(row.getByText('Disabled', { exact: true })).toBeVisible()

  const page = await newPage(browser)
  await page.goto('/login')
  await signIn(page, email, invitedPassword)

  await expect(page.getByRole('alert')).toHaveText('Invalid credentials.')
  await expect(page).toHaveURL('/login')
})

test('a user resets a forgotten password and signs in with the new one', async ({ browser }) => {
  const email = uniqueEmail('reset')
  await createUser(browser, email)
  const newPassword = 'reset-password-2'

  const page = await newPage(browser)
  await page.goto('/login')
  await page.getByRole('link', { name: 'Forgot your password?' }).click()
  await page.getByLabel('Email').fill(email)
  await page.getByRole('button', { name: 'Send reset link' }).click()
  await expect(page.getByRole('status')).toContainText('If an account exists')

  await page.goto(await latestLinkTo(email, '/reset-password'))
  await page.getByLabel('New password', { exact: true }).fill(newPassword)
  await page.getByLabel('Confirm password').fill(newPassword)
  await page.getByRole('button', { name: 'Change password' }).click()
  await expect(page.getByText('Your password has been changed.')).toBeVisible()

  await page.getByRole('link', { name: 'Go to sign in' }).click()
  await signIn(page, email, newPassword)
  await expect(page).toHaveURL('/play')
})
