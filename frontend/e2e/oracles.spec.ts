import { expect, test } from '@playwright/test'

// Users created by `make e2e-seed`.
const player = 'e2e-player@example.test'
const password = process.env.E2E_PASSWORD ?? 'e2e-password-123'

test('a solo player asks the oracles on the Play page', async ({ page }) => {
  await page.goto('/login')
  await page.getByLabel('Email').fill(player)
  await page.getByLabel('Password').fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL('/play')

  await page.getByLabel('Likelihood', { exact: true }).selectOption({ label: 'Likely' })
  await page.getByLabel('Chaos factor').fill('6')
  await page.getByRole('button', { name: 'Ask' }).click()

  const answer = page.getByRole('region', { name: 'Likelihood answer' })
  await expect(answer.getByText(/^(Exceptional yes|Yes|No|Exceptional no)$/)).toBeVisible()
  await expect(answer).toContainText('on d100')
  await expect(answer).toContainText('Chaos factor6')

  await page.getByLabel('Oracle table', { exact: true }).selectOption({ label: 'Weather' })
  await page.getByRole('button', { name: 'Roll on table' }).click()

  // Weather rolls 1d6; a 6 nests a roll on Storm kind, so there are one or two steps.
  const steps = page.getByRole('region', { name: 'Oracle table result' }).getByRole('listitem')
  await expect(steps.first()).toContainText('Weather')
  await expect(steps.first()).toContainText('1d6')
  expect(await steps.count()).toBeLessThanOrEqual(2)
})
