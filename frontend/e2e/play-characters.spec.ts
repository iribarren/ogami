import { expect, test, type Page } from '@playwright/test'

// Users and the "Free journal" preset are seeded by `make e2e-seed`. The preset has no sheet
// fields, so a Character holds a name and tags; the fields are covered by Vitest.
const player = 'e2e-player@example.test'
const password = process.env.E2E_PASSWORD ?? 'e2e-password-123'

async function signIn(page: Page) {
  await page.goto('/login')
  await page.getByLabel('Email').fill(player)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL('/play')
}

test('a solo player creates and edits a Character and promotes an NPC to one', async ({ page }) => {
  await signIn(page)

  // A new campaign per run: the dev database keeps the campaigns of earlier runs.
  const form = page.getByRole('form', { name: 'New campaign' })
  await form.getByLabel('Campaign name').fill(`Characters ${String(Date.now())}`)
  await form.getByLabel('Game system').selectOption('free-journal')
  await form.getByRole('button', { name: 'Create campaign' }).click()
  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)

  const characters = page.getByRole('region', { name: 'Characters' })
  const npcs = page.getByRole('region', { name: 'NPCs' })
  await expect(characters).toContainText('No Characters yet.')

  // Create a Character with a free tag.
  await characters.getByRole('button', { name: 'Add Character' }).click()
  await characters.getByLabel('Name', { exact: true }).fill('Vi')
  await characters.getByLabel('Free tags').fill('Fixer')
  await characters.getByRole('button', { name: 'Add free tag' }).click()
  await characters.getByRole('button', { name: 'Save' }).click()
  await expect(characters).toContainText('Vi')
  await expect(characters).toContainText('Fixer')

  // Edit its name and free tags.
  await characters.getByRole('button', { name: 'Edit Vi' }).click()
  await characters.getByLabel('Name', { exact: true }).fill('Violet')
  await characters.getByRole('button', { name: 'Remove free tag Fixer' }).click()
  await characters.getByLabel('Free tags').fill('Solo')
  await characters.getByRole('button', { name: 'Add free tag' }).click()
  await characters.getByRole('button', { name: 'Save' }).click()
  await expect(characters).toContainText('Violet')
  await expect(characters).toContainText('Solo')
  await expect(characters).not.toContainText('Fixer')

  // Add an NPC and promote it.
  await npcs.getByRole('button', { name: 'Add NPC' }).click()
  await npcs.getByLabel('Name').fill('Mira Vane')
  await npcs.getByRole('button', { name: 'Save' }).click()
  await expect(npcs).toContainText('Mira Vane')
  await npcs.getByRole('button', { name: 'Promote Mira Vane to Character' }).click()
  await npcs.getByRole('button', { name: 'Confirm promotion' }).click()

  // The Character shows in Characters; the NPC is closed and marked promoted.
  await expect(characters).toContainText('Mira Vane')
  await npcs.getByText('Closed (1)').click()
  await expect(npcs).toContainText('Promoted')
  await expect(npcs.getByRole('button', { name: /Promote Mira Vane/ })).toHaveCount(0)

  // Saved: a reload shows the same.
  await page.reload()
  await expect(page.getByRole('region', { name: 'Characters' })).toContainText('Violet')
  await expect(page.getByRole('region', { name: 'Characters' })).toContainText('Mira Vane')
})
