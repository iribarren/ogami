import { expect, test } from '@playwright/test'

import { manager, signIn } from './sign-in.ts'

// Users and the "free-journal" preset are seeded by `make e2e-seed`. Its "Action" table is the
// first of the oracle tables.

test('a game manager edits an oracle table of a copied preset, test-rolls it and publishes', async ({
  page,
}) => {
  // Unique per run: the dev database keeps the GameSystems of earlier runs.
  const key = `e2e-${Date.now().toString(36)}`
  const edited = `Seek out ${key}`

  await signIn(page, manager)
  await page.goto('/studio')
  await page.getByRole('button', { name: 'New GameSystem' }).click()
  const newForm = page.getByRole('form', { name: 'New GameSystem' })
  await page.getByRole('radio', { name: 'Copy a release' }).click()
  await newForm.getByLabel('Source GameSystem key').fill('free-journal')
  await newForm.getByLabel('Key', { exact: true }).fill(key)
  await newForm.getByLabel('Name').fill(`E2E oracles ${key}`)
  await newForm.getByRole('button', { name: 'Create GameSystem' }).click()
  await expect(page).toHaveURL(`/studio/game-systems/${key}`)

  await page.getByRole('radio', { name: 'Oracles' }).click()
  const tables = page.getByRole('region', { name: 'Oracle tables' })
  await expect(tables.getByRole('listitem').first()).toBeVisible()
  await tables.getByRole('link', { name: 'Action' }).click()
  await expect(page).toHaveURL(`/studio/game-systems/${key}/oracle-tables/action`)

  const editor = page.getByRole('form', { name: 'Oracle table' })
  await editor.getByLabel('Entry 1 text').fill(edited)
  // Enter submits the form: the dev server's query devtools badge can sit over the Save button
  // at the foot of a long table.
  await editor.getByLabel('Entry 1 text').press('Enter')
  await expect(editor.getByRole('button', { name: 'Save' })).toBeDisabled()

  // What was saved is what the draft holds, not just what the form shows.
  await page.reload()
  await expect(page.getByLabel('Entry 1 text')).toHaveValue(edited)

  await page.getByRole('button', { name: 'Test roll' }).click()
  const steps = page.getByRole('list', { name: 'Steps' })
  await expect(steps).toBeVisible()
  await expect(steps).toContainText('Action')

  await page.goto(`/studio/game-systems/${key}`)
  await page.getByRole('button', { name: 'Publish' }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByRole('button', { name: 'Publish version 1' }).click()
  await expect(page.getByText('Published version 1.')).toBeVisible()
})
