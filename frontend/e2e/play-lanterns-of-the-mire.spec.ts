import { expect, test } from '@playwright/test'

import { player, signIn } from './sign-in.ts'

// Users and the "Lanterns of the Mire" preset are seeded by `make e2e-seed`. The run through the
// Flow (Clues and Nightfall, the end of the Investigation, Conditions) is covered by the
// LanternsOfTheMirePresetFlowTest unit test: this spec checks the credits and one pool check.

test('a solo player starts Lanterns of the Mire, creates their investigator and rolls a pool check', async ({
  page,
}) => {
  await signIn(page, player, '/play')

  const form = page.getByRole('form', { name: 'New campaign' })
  await form.getByLabel('Campaign name').fill(`Lanterns ${String(Date.now())}`)
  await form.getByLabel('Game system').selectOption('lanterns-of-the-mire')
  await expect(form.getByLabel('Flow')).toHaveValue('a-mystery')
  // The Free Tabletop License (ADR 0021): the notice and the license as a link.
  const about = form.getByRole('region', { name: 'About this game' })
  await expect(about).toContainText('Free Tabletop License')
  await expect(
    about.getByRole('link', { name: 'Year Zero Engine Free Tabletop License 1.1' }),
  ).toHaveAttribute('href', /Year-Zero-Engine-License-Agreement/)
  await form.getByRole('button', { name: 'Create campaign' }).click()
  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)

  await page.getByRole('button', { name: 'Start session' }).click()
  const guide = page.getByRole('region', { name: 'Focus mode' })
  const make = guide.getByRole('region', { name: 'Current step: Make your investigator' })
  await expect(make).toBeVisible()

  // The attributes and the skills start inside their budgets, so the defaults can be saved.
  const characters = page.getByRole('region', { name: 'Characters' })
  await characters.getByRole('button', { name: 'Add Character' }).click()
  await characters.getByLabel('Name', { exact: true }).fill('Edda Voss')
  await characters.getByRole('button', { name: 'Save' }).click()
  await expect(characters).toContainText('Edda Voss')

  await make.getByRole('radio', { name: 'Edda Voss' }).check()
  await make.getByRole('button', { name: 'Choose' }).click()
  await expect(
    guide.getByRole('region', { name: 'Current step: Where the trouble lies' }),
  ).toBeVisible()

  // A skill is a pool of d6: its result, whatever the dice said, is in the journal.
  await characters.getByRole('button', { name: 'Roll a check for Edda Voss' }).click()
  const dialog = page.getByRole('dialog', { name: 'Roll a check for Edda Voss' })
  await dialog.getByLabel('Check').selectOption({ label: 'Brawl (Vigour)' })
  await dialog.getByRole('button', { name: 'Roll' }).click()
  // A roll with a failed die offers Push or Keep, one with all dice succeeding only Done.
  await expect(dialog.getByRole('article', { name: 'Check Brawl (Vigour)' })).toBeVisible()
  await dialog.getByRole('button', { name: /^(Keep|Done)$/ }).click()
  await expect(dialog).toBeHidden()

  await page.getByRole('radio', { name: 'Journal view' }).check()
  await expect(page.getByRole('region', { name: 'Journal' })).toContainText('Brawl (Vigour)')
})
