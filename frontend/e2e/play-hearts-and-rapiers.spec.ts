import { expect, test } from '@playwright/test'

import { player, signIn } from './sign-in.ts'

// Users and the "Hearts & Rapiers" preset are seeded by `make e2e-seed`. The run through the Flow
// (the Setup answers, a scene, the Climax) and the band edges of its moves are covered by the
// HeartsAndRapiersPresetFlowTest unit test: this spec checks the credits and one move.

test('a solo player starts Hearts & Rapiers, creates their hero and rolls a move', async ({
  page,
}) => {
  await signIn(page, player, '/play')

  const form = page.getByRole('form', { name: 'New campaign' })
  await form.getByLabel('Campaign name').fill(`Rapiers ${String(Date.now())}`)
  await form.getByLabel('Game system').selectOption('hearts-and-rapiers')
  await expect(form.getByLabel('Flow')).toHaveValue('one-shot')
  await expect(form.getByRole('region', { name: 'About this game' })).toContainText('CC BY SA 4.0')
  await form.getByRole('button', { name: 'Create campaign' }).click()
  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)

  await page.getByRole('button', { name: 'Start session' }).click()
  const guide = page.getByRole('region', { name: 'Focus mode' })
  await expect(guide.getByRole('region', { name: 'Current step: Create your hero' })).toBeVisible()

  const characters = page.getByRole('region', { name: 'Characters' })
  await characters.getByRole('button', { name: 'Add Character' }).click()
  await characters.getByLabel('Name', { exact: true }).fill('Rosalind')
  await characters.getByRole('button', { name: 'Save' }).click()
  await expect(characters).toContainText('Rosalind')

  const hero = guide.getByRole('region', { name: 'Current step: Create your hero' })
  await hero.getByRole('radio', { name: 'Rosalind' }).check()
  await hero.getByRole('button', { name: 'Choose' }).click()
  await expect(
    guide.getByRole('region', { name: 'Current step: Name the toxic power' }),
  ).toBeVisible()

  // A move is a 2d6 check: its result, whatever the dice said, is in the journal.
  await characters.getByRole('button', { name: 'Roll a check for Rosalind' }).click()
  const dialog = page.getByRole('dialog', { name: 'Roll a check for Rosalind' })
  await dialog.getByLabel('Check').selectOption({ label: 'Fight (Daring)' })
  await dialog.getByRole('button', { name: 'Roll' }).click()
  await expect(dialog).toBeHidden()

  await page.getByRole('radio', { name: 'Journal view' }).check()
  await expect(page.getByRole('region', { name: 'Journal' })).toContainText('Fight (Daring)')
})
