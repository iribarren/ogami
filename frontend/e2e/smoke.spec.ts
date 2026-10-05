import { expect, test } from '@playwright/test'

test('the SPA loads, reports a healthy API and opens Play', async ({ page }) => {
  await page.goto('/')

  await expect(page.getByRole('heading', { level: 1, name: 'Ogami' })).toBeVisible()
  // API and Database values, fetched through the generated client from /api/health.
  const systemStatus = page.getByRole('region', { name: 'System status' })
  await expect(systemStatus.getByRole('definition')).toHaveText(['ok', 'ok'])

  await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Play' }).click()

  await expect(page).toHaveURL('/play')
  await expect(page.getByRole('heading', { level: 1, name: 'Play' })).toBeVisible()
})
