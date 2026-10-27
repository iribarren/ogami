import { expect, test } from '@playwright/test'

import { player, signIn } from './sign-in.ts'

// Users and the "Night Court" preset are seeded by `make e2e-seed`. The run through the Flow (a
// messy critical, a bestial failure, Rouse at Dawn, the Inquisition raid) is covered by the
// VtmNightCourtPresetFlowTest unit test: this spec checks the Dark Pack credits and one Hunger roll.

// 22 attribute points over nine attributes (1 to 4 each) and 14 skill points over twelve (0 to 3).
const sheet: Record<string, string> = {
  Strength: '3',
  Dexterity: '3',
  Stamina: '3',
  Charisma: '3',
  Manipulation: '3',
  Composure: '2',
  Intelligence: '2',
  Wits: '2',
  Resolve: '1',
  Brawl: '3',
  Athletics: '3',
  Stealth: '3',
  Streetwise: '2',
  Persuasion: '3',
}

test('a solo player starts Night Court, sees the Dark Pack credits and rolls a Hunger pool check', async ({
  page,
}) => {
  await signIn(page, player, '/play')

  const form = page.getByRole('form', { name: 'New campaign' })
  await form.getByLabel('Campaign name').fill(`Night Court ${String(Date.now())}`)
  await form.getByLabel('Game system').selectOption('vtm-night-court')
  // The Dark Pack Agreement (ADR 0021): the notice, the logo and the license as a link.
  const about = form.getByRole('region', { name: 'About this game' })
  await expect(about).toContainText('generative AI')
  await expect(about.getByRole('img', { name: 'Dark Pack logo' })).toBeVisible()
  await expect(about.getByRole('link', { name: 'Dark Pack Agreement' })).toHaveAttribute(
    'href',
    /dark-pack-agreement/,
  )
  await form.getByRole('button', { name: 'Create campaign' }).click()
  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)

  // The Character is made on the page, inside both point budgets, so the pool check can be rolled.
  await page.getByRole('button', { name: 'Start session' }).click()
  // A roll is journalled in a scene: Session Zero opens with the city.
  await page.getByRole('button', { name: 'Play City' }).click()
  const characters = page.getByRole('region', { name: 'Characters' })
  await characters.getByRole('button', { name: 'Add Character' }).click()
  await characters.getByLabel('Name', { exact: true }).fill('Vi')
  for (const [field, value] of Object.entries(sheet)) {
    await characters.getByLabel(field, { exact: true }).fill(value)
  }
  await characters.getByRole('button', { name: 'Save' }).click()
  await expect(characters).toContainText('Vi')

  // Brawl is Strength + Brawl dice, one of them a Hunger die per point of Hunger.
  await characters.getByRole('button', { name: 'Roll a check for Vi' }).click()
  const dialog = page.getByRole('dialog', { name: 'Roll a check for Vi' })
  await dialog.getByLabel('Check').selectOption({ label: 'Brawl (Strength)' })
  await dialog.getByRole('button', { name: 'Roll' }).click()
  await expect(dialog.getByRole('article', { name: 'Check Brawl (Strength)' })).toBeVisible()
  await dialog.getByRole('button', { name: /^(Keep|Done)$/ }).click()
  await expect(dialog).toBeHidden()

  await page.getByRole('radio', { name: 'Journal view' }).check()
  const journal = page.getByRole('region', { name: 'Journal' })
  await expect(journal).toContainText('Brawl')
  await expect(journal.getByText('Hunger die').first()).toBeVisible()
})
