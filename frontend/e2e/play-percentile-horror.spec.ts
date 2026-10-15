import { expect, test } from '@playwright/test'

// Users and the "Percentile Horror" preset are seeded by `make e2e-seed`. The first step of its
// Flow sets the investigator's Sanity to their Power (a sheet effect, ADR 0027): this spec checks
// that and nothing more, since the rest of the run is covered by the PercentileHorrorPresetFlowTest
// unit test.
const player = 'e2e-player@example.test'
const password = process.env.E2E_PASSWORD ?? 'e2e-password-123'

test('a solo player creates their investigator, chooses them at the first step and starts with Sanity equal to Power', async ({
  page,
}) => {
  await page.goto('/login')
  await page.getByLabel('Email').fill(player)
  await page.getByLabel('Password').fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL('/play')

  const form = page.getByRole('form', { name: 'New campaign' })
  await form.getByLabel('Campaign name').fill(`Horror ${String(Date.now())}`)
  await form.getByLabel('Game system').selectOption('percentile-horror')
  await expect(form.getByLabel('Flow')).toHaveValue('kingsport-mystery')
  await expect(form.getByRole('region', { name: 'About this game' })).toContainText('ORC License')
  await form.getByRole('button', { name: 'Create campaign' }).click()
  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)

  await page.getByRole('button', { name: 'Start session' }).click()
  const guide = page.getByRole('region', { name: 'Focus mode' })
  const you = guide.getByRole('region', { name: 'Current step: Who are you?' })
  await expect(you).toBeVisible()

  // The tip says to make the investigator first: a Sanity track starts full (99), not at Power.
  const characters = page.getByRole('region', { name: 'Characters' })
  await characters.getByRole('button', { name: 'Add Character' }).click()
  await characters.getByLabel('Name', { exact: true }).fill('Ida Crane')
  await characters.getByLabel('Power', { exact: true }).fill('65')
  await characters.getByRole('button', { name: 'Save' }).click()
  await expect(characters).toContainText('Ida Crane')

  // Choosing them completes the step and sets Sanity to Power.
  await you.getByRole('radio', { name: 'Ida Crane' }).check()
  await you.getByRole('button', { name: 'Choose' }).click()
  await expect(
    guide.getByRole('region', { name: 'Current step: Who calls you to Kingsport?' }),
  ).toBeVisible()

  await page.getByRole('button', { name: 'View sheet of Ida Crane' }).click()
  const sheet = page.getByRole('dialog', { name: 'Ida Crane' })
  await expect(sheet.getByRole('group', { name: 'Tracks' })).toContainText('65 / 99')
})
