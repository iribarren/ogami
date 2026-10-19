import { expect, test, type Page } from '@playwright/test'

import { manager, signIn } from './sign-in.ts'

/**
 * Saves the Scene Type form with Enter: the dev server's query devtools badge can sit over the
 * Save button. Resolves once the API accepted the write.
 */
async function saveSceneType(page: Page) {
  const saved = page.waitForResponse(
    (response) => response.request().method() !== 'GET' && response.url().includes('/api/studio/'),
  )
  await page.getByRole('form', { name: 'Scene Type' }).getByLabel('Name').press('Enter')
  expect((await saved).ok()).toBe(true)
}

/** Adds a ranged oracle table with the default dice to the draft being shown. */
async function addTable(page: Page, key: string, name: string) {
  await page.getByRole('radio', { name: 'Oracles' }).click()
  await page.getByRole('button', { name: 'New table' }).click()
  const form = page.getByRole('form', { name: 'New oracle table' })
  await form.getByLabel('Key').fill(key)
  await form.getByLabel('Name').fill(name)
  await form.getByRole('button', { name: 'Create table' }).click()
  await expect(
    page.getByRole('region', { name: 'Oracle tables' }).getByRole('link', { name }),
  ).toBeVisible()
}

test('a game manager imports a library Scene Type over an oracle the draft overrides, follows a pack change and publishes', async ({
  page,
}) => {
  // Two authors, an import, an update and a publish: more than the default 30 s once the other
  // specs run beside it.
  test.setTimeout(90_000)
  // Unique per run: the dev database keeps the GameSystems and packs of earlier runs.
  const id = Date.now().toString(36)
  const pack = `e2e-pack-${id}`
  const packName = `E2E pack ${id}`
  const draft = `e2e-lib-${id}`
  const table = `omens-${id}`
  const sceneType = `crossroads-${id}`
  const sceneName = `Crossroads ${id}`
  const purpose = 'Decide which road the party takes.'
  const changed = 'Decide which road the party takes, and what it costs.'

  await signIn(page, manager)

  // A library pack with an oracle table and a Scene Type that names it.
  await page.goto('/studio')
  await page.getByRole('button', { name: 'New pack' }).click()
  const newPack = page.getByRole('form', { name: 'New library pack' })
  await newPack.getByLabel('Key').fill(pack)
  await newPack.getByLabel('Name').fill(packName)
  await newPack.getByRole('button', { name: 'Create pack' }).click()
  await expect(page).toHaveURL(`/studio/game-systems/${pack}`)

  await addTable(page, table, `Omens ${id}`)
  await page.getByRole('radio', { name: 'Scene Types' }).click()
  await page.getByRole('button', { name: 'New Scene Type' }).click()
  const newScene = page.getByRole('form', { name: 'New Scene Type' })
  await newScene.getByLabel('Key').fill(sceneType)
  await newScene.getByLabel('Name').fill(sceneName)
  await newScene.getByLabel('Purpose').fill(purpose)
  await newScene.getByRole('button', { name: 'Create Scene Type' }).click()
  await expect(page).toHaveURL(`/studio/game-systems/${pack}/scene-types/${sceneType}`)

  const editor = page.getByRole('form', { name: 'Scene Type' })
  await editor.getByRole('group', { name: 'Oracle shortcuts' }).getByLabel(`Omens ${id}`).check()
  await saveSceneType(page)
  await page.reload()
  await expect(
    page
      .getByRole('form', { name: 'Scene Type' })
      .getByRole('group', { name: 'Oracle shortcuts' })
      .getByLabel(`Omens ${id}`),
  ).toBeChecked()

  // A blank draft is schema version 2; it already has a table under the pack's key.
  await page.goto('/studio')
  await page.getByRole('button', { name: 'New GameSystem' }).click()
  const newForm = page.getByRole('form', { name: 'New GameSystem' })
  await page.getByRole('radio', { name: 'Blank draft' }).click()
  await newForm.getByLabel('Key', { exact: true }).fill(draft)
  await newForm.getByLabel('Name').fill(`E2E library ${id}`)
  await newForm.getByRole('button', { name: 'Create GameSystem' }).click()
  await expect(page).toHaveURL(`/studio/game-systems/${draft}`)
  await addTable(page, table, `My own omens ${id}`)

  // Import the Scene Type: the draft keeps its own table.
  await page.goto(`/studio/game-systems/${draft}`)
  await page.getByRole('radio', { name: 'Library' }).click()
  await page.getByRole('button', { name: 'Import from library' }).first().click()
  const dialog = page.getByRole('dialog')
  await dialog.getByRole('button', { name: `Choose ${packName}` }).click()
  await dialog.getByRole('checkbox', { name: new RegExp(sceneName) }).check()
  await dialog.getByRole('button', { name: 'Preview' }).click()
  const preview = dialog.getByRole('region', { name: 'Preview' })
  await expect(preview.getByText('Kept from your draft')).toBeVisible()
  await expect(preview).toContainText(table)
  await dialog.getByRole('button', { name: 'Import', exact: true }).click()
  await expect(dialog).toBeHidden()

  const imports = page.getByRole('region', { name: 'Library' })
  const row = imports.getByRole('listitem').filter({ hasText: sceneType })
  await expect(row).toContainText('Current')

  // The pack's Scene Type changes: the draft shows it and updates.
  await page.goto(`/studio/game-systems/${pack}/scene-types/${sceneType}`)
  const packEditor = page.getByRole('form', { name: 'Scene Type' })
  await packEditor.getByLabel('Purpose').fill(changed)
  await saveSceneType(page)
  await page.reload()
  await expect(page.getByLabel('Purpose')).toHaveValue(changed)

  await page.goto(`/studio/game-systems/${draft}`)
  await expect(page.getByText('1 library change', { exact: true })).toBeVisible()
  await page.getByRole('radio', { name: 'Library' }).click()
  await expect(row).toContainText('Changed')
  await row.getByRole('button', { name: `Update ${sceneType}` }).click()
  await expect(row).toContainText('Current')
  await expect(page.getByText('1 library change', { exact: true })).toBeHidden()

  // The release says where the imported items came from.
  await page.getByRole('button', { name: 'Publish' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Publish version 1' }).click()
  await expect(page.getByText('Published version 1.')).toBeVisible()
  await page.getByRole('radio', { name: 'Releases' }).click()
  await expect(page.getByRole('region', { name: 'Releases' })).toContainText(
    `Library: ${pack} (${sceneType}`,
  )
})
