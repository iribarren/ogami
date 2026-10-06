import { expect, test } from '@playwright/test'

// Users and the "Free journal" preset are seeded by `make e2e-seed`.
const player = 'e2e-player@example.test'
const password = process.env.E2E_PASSWORD ?? 'e2e-password-123'

test('a solo player creates a campaign from a published GameSystem and opens it', async ({
  page,
}) => {
  // Unique per run: the dev database keeps the campaigns of earlier runs.
  const name = `The lost mine ${String(Date.now())}`

  await page.goto('/login')
  await page.getByLabel('Email').fill(player)
  await page.getByLabel('Password').fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL('/play')

  const form = page.getByRole('form', { name: 'New campaign' })
  await form.getByLabel('Campaign name').fill(name)
  await form.getByLabel('GameSystem').selectOption('free-journal')
  await form.getByRole('button', { name: 'Create campaign' }).click()

  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)
  const campaignUrl = page.url()
  await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
  await expect(page.getByText(/^Free journal v\d+$/)).toBeVisible()
  await expect(page.getByRole('region', { name: 'Where play stands' })).toContainText(
    'No session yet',
  )

  await page.getByRole('link', { name: 'Back to your campaigns' }).click()
  await expect(page).toHaveURL('/play')
  const campaigns = page.getByRole('region', { name: 'Your campaigns' })
  await campaigns.getByRole('link', { name }).click()

  await expect(page).toHaveURL(campaignUrl)
  await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
})
