import { expect, test } from '@playwright/test'

import { player, signIn } from './sign-in.ts'

// Users and the "E2E Corner shop" release (backend/tests/Fixtures/E2e) are seeded by
// `make e2e-seed`. The release has a Character step, then one shop selling a Used pistol (50 eb)
// and a Ration (5 eb); a new Character holds 100 eb.

test('a solo player buys gear at a shop, sees the money go down, the gear in the sheet and the journal entry', async ({
  page,
}) => {
  await signIn(page, player, '/play')

  const form = page.getByRole('form', { name: 'New campaign' })
  await form.getByLabel('Campaign name').fill(`Corner shop ${String(Date.now())}`)
  await form.getByLabel('Game system').selectOption('e2e-corner-shop')
  await form.getByRole('button', { name: 'Create campaign' }).click()
  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)

  await page.getByRole('button', { name: 'Start session' }).click()
  // The Flow's only Scene Type is picked when the session starts, so there is no card to click.
  const guide = page.getByRole('region', { name: 'Focus mode' })

  // The shop needs a Character: create one in the first step.
  const who = guide.getByRole('region', { name: 'Current step: Who goes shopping?' })
  await who.getByLabel('Name', { exact: true }).fill('Vi')
  await who.getByRole('button', { name: 'Save' }).click()

  // Two Rations cost 10 eb of the 100 eb the Character starts with.
  const shop = guide.getByRole('region', { name: 'Current step: What do you buy?' })
  await shop.getByLabel('Buy Ration').fill('2')
  const totals = shop.getByLabel('Totals')
  await expect(totals).toContainText('Spend10 eb')
  await expect(totals).toContainText('Eddies now100 eb')
  await expect(totals).toContainText('Eddies after90 eb')
  await shop.getByRole('button', { name: 'Confirm' }).click()

  // The sheet shows the lower balance and the gear in the Pack.
  const characters = page.getByRole('region', { name: 'Characters' })
  await expect(characters).toContainText('Eddies: 90 eb')
  await expect(characters).toContainText('Ration ×2')

  // The journal tells the visit.
  await page.getByRole('radio', { name: 'Journal view' }).check()
  const entry = page.getByRole('article', { name: 'Shop Vi' })
  await expect(entry).toContainText('2 × Ration @ 5')
  await expect(entry).toContainText('Vi: 100 → 90')
})
