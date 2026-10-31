import { expect, test } from '@playwright/test'

import { player, signIn } from './sign-in.ts'

// Users and the "Night Job" preset (release v2: the heist one-shot and the "Making a Name"
// campaign flow) are seeded by `make e2e-seed`.

test('a solo player continues a heist in the "Making a Name" campaign with their Character', async ({
  page,
}) => {
  await signIn(page, player, '/play')
  const heistName = `Heist ${String(Date.now())}`

  const form = page.getByRole('form', { name: 'New campaign' })
  await form.getByLabel('Campaign name').fill(heistName)
  await form.getByLabel('Game system').selectOption('night-job')
  await expect(form.getByLabel('Flow')).toHaveValue('heist')
  await form.getByRole('button', { name: 'Create campaign' }).click()
  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)
  const heistUrl = page.url()

  // The heist makes the Character and the first crew member.
  await page.getByRole('button', { name: 'Start session' }).click()
  await page.getByRole('button', { name: 'Play Crew' }).click()
  const guide = page.getByRole('region', { name: 'Focus mode' })
  const you = guide.getByRole('region', { name: 'Current step: Who are you?' })
  await you.getByLabel('Name', { exact: true }).fill('Vi')
  await you.getByLabel('Role').fill('Netrunner')
  await you.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByRole('region', { name: 'Characters' })).toContainText('Vi')
  const first = guide.getByRole('region', { name: 'Current step: Who is your first crew member?' })
  await first.getByLabel('Your answer').fill('Ada, a netrunner')
  await first.getByRole('button', { name: 'Record' }).click()
  await expect(page.getByRole('region', { name: 'NPCs' })).toContainText('Ada, a netrunner')

  // Continue in the campaign flow, bringing the Character along.
  await page.getByRole('button', { name: 'Continue in a new campaign' }).click()
  const dialog = page.getByRole('dialog')
  await expect(dialog.getByLabel('New campaign name')).toHaveValue(`${heistName}: Heist`)
  await dialog.getByLabel('Flow').selectOption({ label: 'Making a Name' })
  await expect(dialog.getByLabel('New campaign name')).toHaveValue(`${heistName}: Making a Name`)
  await expect(dialog.getByRole('checkbox', { name: 'Vi' })).toBeChecked()
  await dialog.getByRole('button', { name: 'Create new campaign' }).click()

  // The new campaign has the NPC and the Character, and no session yet.
  await expect(
    page.getByRole('heading', { level: 1, name: `${heistName}: Making a Name` }),
  ).toBeVisible()
  expect(page.url()).not.toBe(heistUrl)
  await expect(page.getByRole('region', { name: 'NPCs' })).toContainText('Ada, a netrunner')
  await expect(page.getByRole('region', { name: 'Characters' })).toContainText('Vi')

  // Street zero's Edgerunner step offers the carried Character instead of a blank sheet.
  await page.getByRole('button', { name: 'Start session' }).click()
  await page.getByRole('button', { name: 'Play Edgerunner' }).click()
  const edgerunner = page
    .getByRole('region', { name: 'Focus mode' })
    .getByRole('region', { name: 'Current step: Who are you?' })
  await edgerunner.getByRole('radio', { name: 'Vi' }).check()
  await edgerunner.getByRole('button', { name: 'Choose' }).click()
  await expect(
    page
      .getByRole('region', { name: 'Focus mode' })
      .getByRole('group', { name: /^Cast of session/ }),
  ).toContainText('Vi')

  // The heist is untouched.
  await page.goto(heistUrl)
  await expect(page.getByRole('heading', { level: 1, name: heistName })).toBeVisible()
})
