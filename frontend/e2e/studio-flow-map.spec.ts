import { expect, test, type Page } from '@playwright/test'

import { chooseOption } from './choose-option.ts'
import { manager, signIn } from './sign-in.ts'

// Users and the "night-job" preset are seeded by `make e2e-seed`. Its "heist" flow has the phases
// "The job", "Legwork" (hooks, and the Scene Types Social, Exploration and Netrun), "The heist",
// "Escape" and "Epilogue"; its Exploration Scene Type has two play steps.

/** The centre of an element, for the mouse: the Map is drawn from real layout in Chromium. */
async function centreOf(page: Page, selector: string) {
  const box = await page.locator(selector).first().boundingBox()
  if (box === null) {
    throw new Error(`No box for ${selector}.`)
  }
  return { x: box.x + box.width / 2, y: box.y + box.height / 2 }
}

test('a game manager edits a flow on the Map: drops a Scene Type, adds a hook transition and a Scene Type transition', async ({
  page,
}) => {
  // Unique per run: the dev database keeps the GameSystems of earlier runs.
  const key = `e2e-map-${Date.now().toString(36)}`

  await signIn(page, manager)
  await page.goto('/studio')
  await page.getByRole('button', { name: 'New GameSystem' }).click()
  const newForm = page.getByRole('form', { name: 'New GameSystem' })
  await page.getByRole('radio', { name: 'Copy a release' }).click()
  await newForm.getByLabel('Source GameSystem').selectOption('night-job')
  await newForm.getByLabel('Key', { exact: true }).fill(key)
  await newForm.getByLabel('Name').fill(`E2E map ${key}`)
  await newForm.getByRole('button', { name: 'Create GameSystem' }).click()
  await expect(page).toHaveURL(`/studio/game-systems/${key}`)

  await page.goto(`/studio/game-systems/${key}/flows/heist`)
  await page.getByRole('tab', { name: 'Map' }).click()
  const map = page.getByRole('region', { name: 'Flow map' })
  await expect(map.getByRole('button', { name: 'Phase: Epilogue' })).toBeVisible()
  await expect(page).toHaveURL(/\?view=map$/)
  await expect(page.getByText('Unsaved changes')).toBeHidden()

  // A Scene Type dragged from the palette into a phase: the draft is unsaved, as after the outline's Add.
  const from = await centreOf(page, '[data-palette-item="social"]')
  const to = await centreOf(page, '[data-phase-id="phase:epilogue"]')
  await page.mouse.move(from.x, from.y)
  await page.mouse.down()
  await page.mouse.move(from.x + 20, from.y + 20, { steps: 4 })
  await page.mouse.move(to.x, to.y, { steps: 12 })
  await page.mouse.up()
  await expect(page.getByRole('status').filter({ hasText: 'Social' })).toBeVisible()
  await expect(page.getByText('Unsaved changes')).toBeVisible()
  await expect(map.getByRole('button', { name: 'Scene Type: Social' })).toHaveCount(2)

  // A transition on a hook, from the action bar of its chip: it lands on the step panel of that hook.
  await map
    .getByRole('button', { name: /^Hook: Scene closing/ })
    .first()
    .focus()
  await page
    .getByRole('group', { name: 'Map actions' })
    .getByRole('button', { name: 'Add transition' })
    .click()
  const dialog = page.getByRole('dialog', { name: 'Add a transition' })
  await chooseOption(page, 'To', 'End of the phase', dialog)
  await dialog.getByRole('button', { name: 'Add transition' }).click()
  await expect(page).toHaveURL(/node=phases\.1\.hooks\.sceneClosing\.0/)
  await expect(page.getByRole('tab', { name: 'Panel' })).toHaveAttribute('aria-selected', 'true')
  await expect(page.getByRole('status').filter({ hasText: 'Added end the phase' })).toBeVisible()

  // Save the flow from the step's own title field, then go on to a Scene Type from the Map.
  const title = page
    .getByRole('form', { name: 'Flow' })
    .getByLabel('Title', { exact: true })
    .first()
  const saved = page.waitForResponse(
    (response) =>
      response.url().includes('/narrative/flows/heist') && response.request().method() === 'PUT',
  )
  await title.press('Enter')
  expect((await saved).ok()).toBe(true)
  await expect(page.getByText('Unsaved changes')).toBeHidden()

  // A transition on a Scene Type: the flow draft does not change, the Scene Type page opens with it unsaved.
  await page.getByRole('tab', { name: 'Map' }).click()
  await map.getByRole('button', { name: 'Scene Type: Exploration' }).first().focus()
  await page
    .getByRole('group', { name: 'Map actions' })
    .getByRole('button', { name: 'Add transition' })
    .click()
  await chooseOption(page, 'To', 'Social', dialog)
  await expect(dialog.getByLabel('Step', { exact: true })).toContainText('Play · 1')
  await dialog.getByRole('button', { name: 'Add transition' }).click()
  await expect(page).toHaveURL(`/studio/game-systems/${key}/scene-types/exploration?step=play.0`)
  await expect(
    page.getByText('Added a transition to "Social" on step 1 of Play. Save to keep it.'),
  ).toBeVisible()
  await expect(page.getByText('Unsaved changes')).toBeVisible()

  // Reloading does not add it a second time.
  await page.reload()
  await expect(page.getByRole('form', { name: 'Scene Type' })).toBeVisible()
  await expect(page.getByText('Unsaved changes')).toBeHidden()
})
