import { expect, test } from '@playwright/test'

import { player, signIn } from './sign-in.ts'

// Users and the "Night Job" preset are seeded by `make e2e-seed`. Night Job defines six checks,
// one per stat (1d10 + the stat against a difficulty). The step path (slip-past, lose-them) is
// covered by the NightJobPresetFlowTest unit test and CampaignFlowChecksApiTest: reaching the
// heist phase through the UI takes some twenty steps.

test('a solo player rolls a check from the sheet and finds it in the journal', async ({ page }) => {
  await signIn(page, player, '/play')

  const form = page.getByRole('form', { name: 'New campaign' })
  await form.getByLabel('Campaign name').fill(`Checks ${String(Date.now())}`)
  await form.getByLabel('Game system').selectOption('night-job')
  await form.getByRole('button', { name: 'Create campaign' }).click()
  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)

  // A check is journaled in the current scene.
  await page.getByRole('button', { name: 'Start session' }).click()
  await page.getByRole('button', { name: 'Play Crew' }).click()

  const characters = page.getByRole('region', { name: 'Characters' })
  await characters.getByRole('button', { name: 'Add Character' }).click()
  await characters.getByLabel('Name', { exact: true }).fill('Vi')
  await characters.getByRole('button', { name: 'Save' }).click()
  await expect(characters).toContainText('Vi')

  // The dialog offers the release's checks, with the difficulty at its default.
  await characters.getByRole('button', { name: 'Roll a check for Vi' }).click()
  const dialog = page.getByRole('dialog', { name: 'Roll a check for Vi' })
  await dialog.getByLabel('Check').selectOption({ label: 'Hands check' })
  await expect(dialog.getByLabel('Difficulty')).toHaveValue('13')
  await dialog.getByRole('button', { name: 'Roll' }).click()
  await expect(dialog).toBeHidden()

  // The result is in the journal, whatever the dice said (the focus view hides the journal).
  await page.getByRole('radio', { name: 'Journal view' }).check()
  await expect(page.getByRole('region', { name: 'Journal' })).toContainText('Hands check')
})
