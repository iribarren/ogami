import { expect, test, type Page } from '@playwright/test'

import { manager, signIn } from './sign-in.ts'

// Users and the "night-job" preset are seeded by `make e2e-seed`. Its "social" Scene Type opens
// with a "talk" prompt step in its play steps, and its "heist" flow starts with the phase "The job".

/** Submits the form with Enter in `field` (a long form can have the dev badge over its Save button) and waits for the write. */
async function saveWith(page: Page, field: ReturnType<Page['getByLabel']>, path: string) {
  const saved = page.waitForResponse(
    (response) => response.url().includes(path) && response.request().method() === 'PUT',
  )
  await field.press('Enter')
  expect((await saved).ok()).toBe(true)
}

test('a game manager edits a Scene Type step and a flow phase of a copied preset and publishes', async ({
  page,
}) => {
  // Unique per run: the dev database keeps the GameSystems of earlier runs.
  const key = `e2e-${Date.now().toString(36)}`
  const stepTitle = `Who do you meet in ${key}?`
  const phaseName = `Prepare ${key}`
  const phaseAct = 'Act one'

  await signIn(page, manager)
  await page.goto('/studio')
  await page.getByRole('button', { name: 'New GameSystem' }).click()
  const newForm = page.getByRole('form', { name: 'New GameSystem' })
  await page.getByRole('radio', { name: 'Copy a release' }).click()
  await newForm.getByLabel('Source GameSystem key').fill('night-job')
  await newForm.getByLabel('Key', { exact: true }).fill(key)
  await newForm.getByLabel('Name').fill(`E2E flows ${key}`)
  await newForm.getByRole('button', { name: 'Create GameSystem' }).click()
  await expect(page).toHaveURL(`/studio/game-systems/${key}`)

  // A Scene Type step.
  await page.getByRole('link', { name: 'Scene Types' }).click()
  const sceneTypes = page.getByRole('region', { name: 'Scene Types' })
  await sceneTypes.getByRole('link', { name: 'Social' }).click()
  await expect(page).toHaveURL(`/studio/game-systems/${key}/scene-types/social`)

  const title = page
    .getByRole('region', { name: 'Play', exact: true })
    .getByRole('group', { name: 'Step 1' })
    .getByLabel('Title', { exact: true })
  await title.fill(stepTitle)
  await saveWith(page, title, `/narrative/scene-types/social`)

  // What was saved is what the draft holds, not just what the form shows.
  await page.reload()
  await expect(title).toHaveValue(stepTitle)

  // A flow phase.
  await page.goto(`/studio/game-systems/${key}`)
  await page.getByRole('link', { name: 'Flows' }).click()
  await page.getByRole('region', { name: 'Flows' }).getByRole('link', { name: 'Heist' }).click()
  await expect(page).toHaveURL(`/studio/game-systems/${key}/flows/heist`)

  // The outline selects the phase; its fields are the only ones on the page.
  await page
    .getByRole('navigation', { name: 'Flow outline' })
    .getByRole('button', { name: /^1\. / })
    .click()
  await expect(page).toHaveURL(/\?node=phases\.0$/)
  const form = page.getByRole('form', { name: 'Flow' })
  await form.getByLabel('Name', { exact: true }).fill(phaseName)
  const act = form.getByLabel('Act', { exact: true })
  await act.fill(phaseAct)
  await saveWith(page, act, `/narrative/flows/heist`)

  // The selected node survives a reload, and so does what was saved.
  const escaped = phaseName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  await page.reload()
  const reloaded = page.getByRole('form', { name: 'Flow' })
  await expect(reloaded.getByLabel('Name', { exact: true })).toHaveValue(phaseName)
  await expect(reloaded.getByLabel('Act', { exact: true })).toHaveValue(phaseAct)
  await expect(
    page
      .getByRole('navigation', { name: 'Flow outline' })
      .getByRole('button', { name: new RegExp(`^1\\. ${escaped}`) }),
  ).toHaveAttribute('aria-current', 'true')

  // Publish the draft with both edits.
  await page.goto(`/studio/game-systems/${key}`)
  await page.getByRole('button', { name: 'Publish' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Publish version 1' }).click()
  await expect(page.getByText('Published version 1.')).toBeVisible()
  await page.getByRole('link', { name: 'Releases' }).click()
  await expect(page.getByRole('region', { name: 'Releases' })).toContainText('Version 1')
})
