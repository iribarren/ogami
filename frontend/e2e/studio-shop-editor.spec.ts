import { expect, test, type Page } from '@playwright/test'

import { chooseOption } from './choose-option.ts'
import { manager, signIn } from './sign-in.ts'

// Users and the "E2E Corner shop" release (backend/tests/Fixtures/E2e) are seeded by
// `make e2e-seed`. It has a gear catalog (a Used pistol and a Ration), the Eddies currency, a Pack
// inventory and a Scene Type "market" whose play steps hold one shop.

/** Submits the form with Enter in `field` and waits for the write. */
async function saveWith(page: Page, field: ReturnType<Page['getByLabel']>, path: string) {
  const saved = page.waitForResponse(
    (response) => response.url().includes(path) && response.request().method() === 'PUT',
  )
  await field.press('Enter')
  expect((await saved).ok()).toBe(true)
}

test('a game manager adds a shop step that offers a gear entry and a category with a fixed stock', async ({
  page,
}) => {
  // Unique per run: the dev database keeps the GameSystems of earlier runs.
  const key = `e2e-${Date.now().toString(36)}`

  await signIn(page, manager)
  await page.goto('/studio')
  await page.getByRole('button', { name: 'New GameSystem' }).click()
  const newForm = page.getByRole('form', { name: 'New GameSystem' })
  await page.getByRole('radio', { name: 'Copy a release' }).click()
  await newForm.getByLabel('Source GameSystem key').fill('e2e-corner-shop')
  await newForm.getByLabel('Key', { exact: true }).fill(key)
  await newForm.getByLabel('Name').fill(`E2E shop ${key}`)
  await newForm.getByRole('button', { name: 'Create GameSystem' }).click()
  await expect(page).toHaveURL(`/studio/game-systems/${key}`)

  // A catalog entry with a category, which the shop will offer as a whole.
  await page.getByRole('link', { name: 'Gear' }).click()
  await page.getByRole('button', { name: 'Add gear' }).click()
  const gear = page.getByRole('form', { name: 'New gear' })
  await gear.getByLabel('Name', { exact: true }).fill('Medkit')
  await gear.getByLabel('Cost', { exact: true }).fill('20')
  await gear.getByLabel('Category', { exact: true }).fill('Medical')
  await gear.getByRole('button', { name: 'Save gear' }).click()
  await expect(page.getByRole('region', { name: 'Gear' })).toContainText('Medkit')

  // A second shop step in the Scene Type: the Ration by key and the Medical category, two in stock.
  await page.goto(`/studio/game-systems/${key}/scene-types/market`)
  const play = page.getByRole('region', { name: 'Play', exact: true })
  await play.getByRole('button', { name: 'Add step' }).click()
  await page.getByRole('menuitem', { name: 'shop' }).click()
  const step = play.getByRole('group', { name: 'Step 2' })
  const title = step.getByLabel('Title', { exact: true })
  await title.fill('The back room')
  await step.getByRole('checkbox', { name: 'Ration' }).check()
  await step.getByRole('checkbox', { name: 'Medical' }).check()
  await chooseOption(page, 'Stock', 'A fixed number of entries', step)
  await step.getByLabel('Stock count').fill('2')
  await saveWith(page, title, '/narrative/scene-types/market')

  // What was saved is what the draft holds, not just what the form shows.
  await page.reload()
  const reloaded = page
    .getByRole('region', { name: 'Play', exact: true })
    .getByRole('group', { name: 'Step 2' })
  await expect(reloaded.getByLabel('Title', { exact: true })).toHaveValue('The back room')
  await expect(reloaded.getByRole('checkbox', { name: 'Ration' })).toBeChecked()
  await expect(reloaded.getByRole('checkbox', { name: 'Medical' })).toBeChecked()
  await expect(reloaded.getByRole('checkbox', { name: 'Used pistol' })).not.toBeChecked()
  await expect(reloaded.getByLabel('Stock count')).toHaveValue('2')
})
