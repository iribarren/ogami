import { expect, test } from '@playwright/test'

// Users and the "Free journal" preset are seeded by `make e2e-seed`.
const player = 'e2e-player@example.test'
const password = process.env.E2E_PASSWORD ?? 'e2e-password-123'

// A phone: the campaign page must fit without scrolling sideways.
test.use({ viewport: { width: 360, height: 740 } })

test('the Play screen fits a phone and its panels collapse', async ({ page }) => {
  await page.goto('/login')
  await page.getByLabel('Email').fill(player)
  await page.getByLabel('Password').fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL('/play')

  const form = page.getByRole('form', { name: 'New campaign' })
  await form.getByLabel('Campaign name').fill(`Phone ${String(Date.now())}`)
  await form.getByLabel('Game system').selectOption('free-journal')
  await form.getByRole('button', { name: 'Create campaign' }).click()
  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)
  await expect(page.getByRole('region', { name: 'Where play stands' })).toBeVisible()

  const overflow = await page.evaluate<number>(
    'document.documentElement.scrollWidth - document.documentElement.clientWidth',
  )
  expect(overflow).toBeLessThanOrEqual(0)

  const dice = page.getByRole('button', { name: 'Dice', exact: true })
  await expect(dice).toHaveAttribute('aria-expanded', 'true')
  await dice.click()
  await expect(dice).toHaveAttribute('aria-expanded', 'false')
  await expect(page.getByLabel('Dice expression', { exact: true })).toBeHidden()
  await dice.click()
  await expect(page.getByLabel('Dice expression', { exact: true })).toBeVisible()
})
