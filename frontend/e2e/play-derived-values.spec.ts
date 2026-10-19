import { expect, test } from '@playwright/test'

import { player, signIn } from './sign-in.ts'

// Users and the "Night Job" preset are seeded by `make e2e-seed`. Night Job's sheet has two
// derived fields: Cool (Nerve minus half the Alarm) and Wounded at (half the HP maximum).

test('derived values on the sheet follow the stats the player types', async ({ page }) => {
  await signIn(page, player, '/play')

  const form = page.getByRole('form', { name: 'New campaign' })
  await form.getByLabel('Campaign name').fill(`Derived ${String(Date.now())}`)
  await form.getByLabel('Game system').selectOption('night-job')
  await form.getByRole('button', { name: 'Create campaign' }).click()
  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)

  const characters = page.getByRole('region', { name: 'Characters' })
  await characters.getByRole('button', { name: 'Add Character' }).click()
  await characters.getByLabel('Name', { exact: true }).fill('Vi')

  // The Alarm clock starts at 0, so Cool equals Nerve; Wounded at is half the HP maximum (40).
  const cool = characters.getByLabel('Cool')
  await expect(cool).toHaveText('4')
  await expect(characters.getByLabel('Wounded at')).toHaveText('20')

  // Changing Nerve recomputes Cool as the player types, with no save.
  await characters.getByLabel('Nerve').fill('7')
  await expect(cool).toHaveText('7')
  await characters.getByLabel('Nerve').fill('')
  await expect(cool).toHaveText('— (incomplete value)')

  // A derived value is never stored: saving sends the stats only, and the panel shows the server's.
  await characters.getByLabel('Nerve').fill('6')
  await characters.getByRole('button', { name: 'Save' }).click()
  await expect(characters).toContainText('Vi')
  await expect(characters).toContainText('6')
})
