import { expect, test } from '@playwright/test'

// Users and the "Mythic-style" preset, whose default Flow guides the campaign, are seeded by
// `make e2e-seed`.
const player = 'e2e-player@example.test'
const password = process.env.E2E_PASSWORD ?? 'e2e-password-123'

test('a solo player plays the first step of a guided campaign', async ({ page }) => {
  await page.goto('/login')
  await page.getByLabel('Email').fill(player)
  await page.getByLabel('Password').fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL('/play')

  // A new campaign per run, along the release's default Flow.
  const form = page.getByRole('form', { name: 'New campaign' })
  await form.getByLabel('Campaign name').fill(`Guided ${String(Date.now())}`)
  await form.getByLabel('GameSystem').selectOption('mythic-style')
  await expect(form.getByLabel('Flow')).toHaveValue('mythic')
  await form.getByRole('button', { name: 'Create campaign' }).click()
  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)

  const guide = page.getByRole('region', { name: 'Guided play' })
  await expect(guide).toContainText('Start a session to go on.')
  await page.getByRole('button', { name: 'Start session' }).click()

  // The premise phase offers its one Scene Type itself: the first step waits at once.
  const premise = guide.getByRole('region', { name: 'Current step: What is the premise?' })
  await premise.getByLabel('Your answer').fill('A lighthouse keeper vanishes')
  await premise.getByRole('button', { name: 'Record' }).click()

  // The answer is in the journal, and the panel names and shows the next step.
  const journal = page.getByRole('region', { name: 'Journal' })
  await expect(journal.getByText('Flow step · What is the premise?')).toBeVisible()
  await expect(journal.getByText('A lighthouse keeper vanishes')).toBeVisible()
  await expect(guide).toContainText('Next:')
  await expect(
    guide.getByRole('region', { name: 'Current step: What is your first goal?' }),
  ).toBeVisible()
})
