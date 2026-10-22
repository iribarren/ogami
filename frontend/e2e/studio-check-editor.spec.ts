import { expect, test } from '@playwright/test'

import { chooseOption } from './choose-option.ts'
import { manager, signIn } from './sign-in.ts'

// Users and the "night-job" preset are seeded by `make e2e-seed`. The copy is a schema 2 draft
// with a sheet, trackers and checks of its own: the new check below reads none of them.

test('a game manager authors a check with two bands, rolls it in the sandbox and sees the band reached', async ({
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
  await newForm.getByLabel('Name').fill(`E2E checks ${key}`)
  await newForm.getByRole('button', { name: 'Create GameSystem' }).click()
  await expect(page).toHaveURL(`/studio/game-systems/${key}`)

  await page.getByRole('link', { name: 'Checks' }).click()
  await page.getByRole('button', { name: 'New check' }).click()
  const form = page.getByRole('form', { name: 'New check' })
  await form.getByLabel('Key', { exact: true }).fill('e2e-skill')
  await form.getByLabel('Label', { exact: true }).fill('E2E skill')
  await form.getByLabel('Dice').fill('1d6')
  await form.getByLabel('Modifier').fill('2')

  // A new check starts with one band: add a second, so the first one needs a bound.
  await form.getByRole('button', { name: 'Add band' }).click()
  await form.getByLabel('Band 1 key').fill('low')
  await form.getByLabel('Band 1 label').fill('Low')
  await chooseOption(page, 'Band 1 bound', 'number', form)
  await form.getByLabel('Band 1 number').fill('5')
  await form.getByLabel('Band 2 key').fill('high')
  await form.getByLabel('Band 2 label').fill('High')
  await form.getByRole('button', { name: 'Create check' }).click()

  // The saved check stays open, with the sandbox beside it: bounds for the samples, live.
  const edit = page.getByRole('form', { name: 'Edit e2e-skill' })
  await expect(edit).toBeVisible()
  const sandbox = page.getByRole('region', { name: 'Test roll sandbox' })
  const bounds = sandbox.getByRole('list', { name: 'Band bounds' }).getByRole('listitem')
  await expect(bounds).toHaveText(['Lowup to 5', 'Highthe rest'])

  // Roll it: whatever the dice give, the result has its dice, its total and one band reached.
  await sandbox.getByRole('button', { name: 'Roll the check' }).click()
  // The results' own items: each one holds a nested list of bands.
  const results = sandbox.getByRole('list', { name: 'Test roll results' }).locator(':scope > li')
  await expect(results).toHaveCount(1)
  await expect(results.first()).toContainText('1d6')
  await expect(results.first()).toContainText(/Total \d+/)
  await expect(results.first().getByText('Modifier 2')).toBeVisible()
  const reached = results.first().getByRole('list', { name: 'Bands' }).getByRole('listitem')
  await expect(reached).toHaveCount(2)
  await expect(results.first().locator('[aria-current="true"]')).toHaveCount(1)
  await expect(results.first().getByText('Reached')).toHaveCount(1)

  // An edit is saved first: the next roll comes from the saved check, and the list shows it.
  await edit.getByLabel('Label', { exact: true }).fill('E2E skill, renamed')
  await sandbox.getByRole('button', { name: 'Roll the check' }).click()
  await expect(results).toHaveCount(2)
  await expect(page.getByRole('listitem').filter({ hasText: 'E2E skill, renamed' })).toBeVisible()

  // What was saved is what the draft holds, not just what the form shows.
  await page.reload()
  await page.getByRole('link', { name: 'Checks' }).click()
  await page.getByRole('button', { name: 'Edit e2e-skill' }).click()
  await expect(
    page.getByRole('form', { name: 'Edit e2e-skill' }).getByLabel('Band 2 key'),
  ).toHaveValue('high')
})
