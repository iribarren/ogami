import { expect, test } from '@playwright/test'

test('the SPA loads, reports a healthy API and asks to sign in before Play', async ({ page }) => {
  await page.goto('/')

  await expect(page.getByRole('heading', { level: 1, name: 'Ogami' })).toBeVisible()
  // API and Database values, fetched through the generated client from /api/health.
  const systemStatus = page.getByRole('region', { name: 'System status' })
  await expect(systemStatus.getByRole('definition')).toHaveText(['ok', 'ok'])

  // Areas are role-guarded: an anonymous visitor is sent to the sign-in page.
  await page.getByRole('link', { name: 'Go to Play' }).click()

  await expect(page).toHaveURL('/login?redirect=%2Fplay')
  await expect(page.getByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible()
})
