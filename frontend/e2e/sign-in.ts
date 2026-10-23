import { expect, type Page } from '@playwright/test'

// Users created by `make e2e-seed`.
export const player = 'e2e-player@example.test'
export const manager = 'e2e-manager@example.test'
export const owner = 'e2e-owner@example.test'
export const password = seededPassword()

// The Makefile holds the only default and passes it through the playwright service.
function seededPassword(): string {
  const value = process.env.E2E_PASSWORD
  if (value === undefined || value === '') {
    throw new Error('E2E_PASSWORD is not set; run the tests with `make e2e`')
  }
  return value
}

/**
 * Fills the sign-in form on the current page and submits it. Asserts nothing, for scenarios that
 * open the page themselves or expect the sign-in to fail. Defaults to the seeded password.
 */
export async function submitSignIn(page: Page, email: string, secret: string = password) {
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password', { exact: true }).fill(secret)
  await page.getByRole('button', { name: 'Sign in' }).click()
}

/**
 * Opens the sign-in page and signs in with the seeded password. Waits for `landing` when given
 * (for example `/play`), else for any page other than the sign-in page.
 */
export async function signIn(page: Page, email: string, landing?: string) {
  await page.goto('/login')
  await submitSignIn(page, email)
  if (landing === undefined) {
    await expect(page).not.toHaveURL(/\/login/)
  } else {
    await expect(page).toHaveURL(landing)
  }
}
