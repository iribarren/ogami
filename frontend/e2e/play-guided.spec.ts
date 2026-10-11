import { expect, test } from '@playwright/test'

// Users are seeded by `make e2e-seed`. This spec needs a published GameSystem "guided-tour" whose
// default Flow "tour" lets the player pick the Scene Type "Tour", whose first setup step is the
// prompt "Who walks in?" followed by another step. No preset ships one yet, so it skips itself.
const player = 'e2e-player@example.test'
const password = process.env.E2E_PASSWORD ?? 'e2e-password-123'

test('a solo player plays the first steps of a guided campaign', async ({ page }) => {
  await page.goto('/login')
  await page.getByLabel('Email').fill(player)
  await page.getByLabel('Password').fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL('/play')

  const form = page.getByRole('form', { name: 'New campaign' })
  const guidedTour = form.getByLabel('GameSystem').locator('option[value="guided-tour"]')
  const published = (await guidedTour.count()) > 0
  test.skip(!published, 'No "guided-tour" preset with a Flow is published.')

  await form.getByLabel('Campaign name').fill(`Guided ${String(Date.now())}`)
  await form.getByLabel('GameSystem').selectOption('guided-tour')
  await expect(form.getByLabel('Flow')).toHaveValue('tour')
  await form.getByRole('button', { name: 'Create campaign' }).click()
  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)

  const guide = page.getByRole('region', { name: 'Guided play' })
  await expect(guide).toContainText('Start a session to go on.')
  await page.getByRole('button', { name: 'Start session' }).click()

  // Pick the Scene Type, then answer the first step.
  await guide.getByRole('button', { name: 'Play Tour' }).click()
  const step = guide.getByRole('region', { name: 'Current step: Who walks in?' })
  await step.getByLabel('Your answer').fill('A tall stranger')
  await step.getByRole('button', { name: 'Record' }).click()

  // The answer is in the journal, and the panel names what comes next.
  const journal = page.getByRole('region', { name: 'Journal' })
  await expect(journal.getByText('Flow step · Who walks in?')).toBeVisible()
  await expect(journal.getByText('A tall stranger')).toBeVisible()
  await expect(guide.getByText(/^Next:/)).toBeVisible()
  await expect(guide.getByRole('region', { name: 'Current step: Who walks in?' })).toHaveCount(0)
})
