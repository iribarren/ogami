import { expect, test } from '@playwright/test'

import { chooseOption } from './choose-option.ts'
import { manager, signIn } from './sign-in.ts'

// Users and the "night-job" preset are seeded by `make e2e-seed`. Its sheet declares the groups
// Identity, Stats, Condition and Kit on two sheet columns, and its fields keep their own keys.

test('a game manager lays out the sheet of a copied preset, adds a derived field with a checked formula and sees it in the preview', async ({
  page,
}) => {
  // Unique per run: the dev database keeps the GameSystems of earlier runs.
  const key = `e2e-${Date.now().toString(36)}`

  await signIn(page, manager)
  await page.goto('/studio')
  await page.getByRole('button', { name: 'New GameSystem' }).click()
  const newForm = page.getByRole('form', { name: 'New GameSystem' })
  await page.getByRole('radio', { name: 'Copy a release' }).click()
  await newForm.getByLabel('Source GameSystem key').fill('night-job')
  await newForm.getByLabel('Key', { exact: true }).fill(key)
  await newForm.getByLabel('Name').fill(`E2E sheet ${key}`)
  await newForm.getByRole('button', { name: 'Create GameSystem' }).click()
  await expect(page).toHaveURL(`/studio/game-systems/${key}`)

  await page.getByRole('link', { name: 'Sheet' }).click()
  const preview = page.getByRole('region', { name: 'Preview' })
  // The preset's layout is the preview's: its groups are boxes on the sheet.
  await expect(preview.getByRole('group', { name: 'Stats' })).toBeVisible()

  // Layout: a fifth group, declared next to the preset's four.
  await page.getByRole('button', { name: 'Edit layout' }).click()
  const layout = page.getByRole('form', { name: 'Edit layout' })
  await layout.getByRole('button', { name: 'Add group' }).click()
  await layout.getByRole('group', { name: 'Group 5' }).getByLabel('Name').fill('Extras')
  await layout.getByRole('button', { name: 'Save layout' }).click()
  await expect(layout).toBeHidden()

  // A number field in the new group, with a default the derived field can read.
  await page.getByRole('button', { name: 'Add field' }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByLabel('Key', { exact: true }).fill('luck')
  await dialog.getByLabel('Label', { exact: true }).fill('Luck')
  await chooseOption(page, 'Group', 'Extras', dialog)
  await dialog.getByLabel('Default', { exact: true }).fill('3')
  await dialog.getByRole('button', { name: 'Create field' }).click()
  await expect(dialog).toBeHidden()

  // A derived field: the formula input says a reference is unknown, then the value it computes.
  await page.getByRole('button', { name: 'Add field' }).click()
  await dialog.getByLabel('Key', { exact: true }).fill('luck-twice')
  await dialog.getByLabel('Label', { exact: true }).fill('Luck twice')
  await chooseOption(page, 'Group', 'Extras', dialog)
  await chooseOption(page, 'Type', 'derived', dialog)
  const formula = dialog.getByLabel('Formula')
  await formula.fill('{ghost} * 2')
  await expect(dialog.getByText('{ghost}: unknown field "ghost"')).toBeVisible()
  await formula.fill('')
  await chooseOption(page, 'Insert reference', 'luck', dialog)
  await formula.pressSequentially(' * 2')
  await expect(formula).toHaveValue('{luck} * 2')
  await expect(dialog.getByText('Value with the sample values: 6')).toBeVisible()
  await dialog.getByRole('button', { name: 'Create field' }).click()
  await expect(dialog).toBeHidden()
  await expect(preview.getByLabel('Luck twice')).toHaveText('6')

  // What was saved is what the draft holds, not just what the form shows.
  await page.reload()
  await page.getByRole('link', { name: 'Sheet' }).click()
  await expect(preview.getByRole('group', { name: 'Extras' })).toBeVisible()
  await expect(preview.getByLabel('Luck twice')).toHaveText('6')
})
