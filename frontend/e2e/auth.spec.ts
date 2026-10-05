import { expect, test, type Page } from '@playwright/test'

// Users created by `make e2e-seed`.
const player = 'e2e-player@example.test'
const password = process.env.E2E_PASSWORD ?? 'e2e-password-123'

async function signIn(page: Page, email: string, secret: string) {
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password').fill(secret)
  await page.getByRole('button', { name: 'Sign in' }).click()
}

test('an anonymous visitor signs in and returns to Play', async ({ page }) => {
  await page.goto('/play')

  await expect(page).toHaveURL('/login?redirect=%2Fplay')
  await signIn(page, player, password)

  await expect(page).toHaveURL('/play')
  await expect(page.getByRole('heading', { level: 1, name: 'Play' })).toBeVisible()
})

test('wrong credentials are rejected', async ({ page }) => {
  await page.goto('/login')

  // An unknown email: the answer is the same, and the seeded users stay unthrottled.
  await signIn(page, 'nobody@example.test', 'not-the-password')

  await expect(page.getByRole('alert')).toHaveText('Invalid credentials.')
  await expect(page).toHaveURL('/login')
})

test.describe('signed in as a solo player', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/login')
    await signIn(page, player, password)
    await expect(page).toHaveURL('/play')
  })

  test('the navigation offers only Play', async ({ page }) => {
    const nav = page.getByRole('navigation', { name: 'Main' })

    await expect(nav.getByRole('link')).toHaveText(['Play'])
    await expect(page.getByText(player)).toBeVisible()
  })

  test('Studio shows an access denied page', async ({ page }) => {
    await page.goto('/studio')

    await expect(page).toHaveURL('/studio')
    await expect(page.getByRole('heading', { level: 1, name: 'Access denied' })).toBeVisible()
    await expect(page.getByText("You don't have access to Studio.")).toBeVisible()
  })

  test('signing out returns to the sign-in page and guards Play again', async ({ page }) => {
    await page.getByRole('button', { name: 'Sign out' }).click()

    await expect(page).toHaveURL('/login')
    await expect(page.getByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible()

    await page.goto('/play')
    await expect(page).toHaveURL('/login?redirect=%2Fplay')
  })
})
