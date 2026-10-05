import { expect, test } from '@playwright/test'

// Users created by `make e2e-seed`.
const player = 'e2e-player@example.test'
const password = process.env.E2E_PASSWORD ?? 'e2e-password-123'

test('a solo player rolls dice on the Play page', async ({ page }) => {
  await page.goto('/login')
  await page.getByLabel('Email').fill(player)
  await page.getByLabel('Password').fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL('/play')

  await page.getByLabel('Dice expression').fill('2d6+1')
  await page.getByRole('button', { name: 'Roll', exact: true }).click()

  const result = page.getByRole('region', { name: 'Roll result' })
  await expect(result.getByText('2d6+1', { exact: true })).toBeVisible()
  await expect(result.getByText('Total')).toBeVisible()
  // Two six-sided dice: each face is 1–6, and the total is their sum plus one.
  const dice = result.getByRole('list', { name: '2d6 dice' }).getByRole('listitem')
  await expect(dice).toHaveCount(2)
  const faces = (await dice.allTextContents()).map(Number)
  for (const face of faces) {
    expect(face).toBeGreaterThanOrEqual(1)
    expect(face).toBeLessThanOrEqual(6)
  }
  const total = await result.locator('[aria-describedby]').textContent()
  expect(Number(total)).toBe(faces.reduce((sum, face) => sum + face, 1))
})
