import { expect, test } from '@playwright/test'

// Users and the "Night Job" preset, a fan preset with credits and a Flow introduction, are seeded
// by `make e2e-seed`.
const player = 'e2e-player@example.test'
const password = process.env.E2E_PASSWORD ?? 'e2e-password-123'

test('a solo player sees the disclaimer and the introduction, then completes the crew step', async ({
  page,
}) => {
  await page.goto('/login')
  await page.getByLabel('Email').fill(player)
  await page.getByLabel('Password').fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL('/play')

  // Choosing the preset shows the publisher's disclaimer and what the Flow is about.
  const form = page.getByRole('form', { name: 'New campaign' })
  await form.getByLabel('Campaign name').fill(`Night Job ${String(Date.now())}`)
  await form.getByLabel('Game system').selectOption('night-job')
  await expect(form.getByLabel('Flow')).toHaveValue('heist')
  const about = form.getByRole('region', { name: 'About this game' })
  await expect(about).toContainText('Night Job is unofficial content provided under the Homebrew')
  await expect(about).toContainText('not approved or endorsed by RTG')
  await expect(
    about.getByRole('link', { name: 'R. Talsorian Games Homebrew Content Policy' }),
  ).toHaveAttribute('href', 'https://rtalsoriangames.com/homebrew-content-policy/')
  await expect(form).toContainText('Solo play means you are the whole table')
  await form.getByRole('button', { name: 'Create campaign' }).click()
  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)

  // The disclaimer stays on the campaign page.
  await expect(page.getByRole('region', { name: 'About this game' })).toContainText(
    'not approved or endorsed by RTG',
  )

  // The job starts with two Scene Types in sequence: pick the crew, then answer its mandatory step.
  await page.getByRole('button', { name: 'Start session' }).click()
  await page.getByRole('button', { name: 'Play Crew' }).click()
  // The Flow opens in the focus view, which holds the current step.
  const guide = page.getByRole('region', { name: 'Focus mode' })
  const first = guide.getByRole('region', { name: 'Current step: Who is your first crew member?' })
  await first.getByLabel('Your answer').fill('Ada, a netrunner')
  await first.getByRole('button', { name: 'Record' }).click()

  // The crew member joins the NPCs, and the guide moves to the next, suggested step.
  await expect(page.getByRole('region', { name: 'NPCs' })).toContainText('Ada, a netrunner')
  await expect(guide.getByRole('region', { name: 'Current step: Who else is in?' })).toBeVisible()
})
