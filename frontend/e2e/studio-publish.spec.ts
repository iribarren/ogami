import { expect, test, type Page } from '@playwright/test'

// Users and the "free-journal" preset are seeded by `make e2e-seed`.
const manager = 'e2e-manager@example.test'
const player = 'e2e-player@example.test'
const password = process.env.E2E_PASSWORD ?? 'e2e-password-123'

async function signIn(page: Page, email: string) {
  await page.goto('/login')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password').fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).not.toHaveURL(/\/login/)
}

test('a game manager publishes a GameSystem in Studio and a solo player starts a campaign on it', async ({
  page,
}) => {
  // Unique per run: the dev database keeps the GameSystems of earlier runs. A key is lower case
  // letters, digits and hyphens.
  const key = `e2e-${Date.now().toString(36)}`
  const name = `E2E journal ${key}`
  const notes = 'First release from the e2e test.'

  await signIn(page, manager)
  await page.goto('/studio')
  await page.getByRole('button', { name: 'New GameSystem' }).click()

  const newForm = page.getByRole('form', { name: 'New GameSystem' })
  await page.getByRole('radio', { name: 'Copy a release' }).click()
  await newForm.getByLabel('Source GameSystem key').fill('free-journal')
  await newForm.getByLabel('Key', { exact: true }).fill(key)
  await newForm.getByLabel('Name').fill(name)
  await newForm.getByRole('button', { name: 'Create GameSystem' }).click()

  await expect(page).toHaveURL(`/studio/game-systems/${key}`)
  await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
  await expect(page.getByText('Never published')).toBeVisible()

  const metadata = page.getByRole('form', { name: 'Metadata' })
  await metadata.getByLabel('Description').fill('Published from the e2e test.')
  await metadata.getByRole('button', { name: 'Save' }).click()
  await expect(metadata.getByRole('status')).toHaveText('Saved.')

  await page.getByRole('button', { name: 'Publish' }).click()
  const dialog = page.getByRole('dialog')
  await expect(dialog.getByText('This creates version 1.')).toBeVisible()
  await dialog.getByLabel('Release notes (optional)').fill(notes)
  await dialog.getByRole('button', { name: 'Publish version 1' }).click()

  await expect(page.getByText('Published version 1.')).toBeVisible()
  await expect(page.getByText('Latest release v1')).toBeVisible()
  await page.getByRole('radio', { name: 'Releases' }).click()
  const releases = page.getByRole('region', { name: 'Releases' })
  await expect(releases.getByRole('listitem')).toHaveCount(1)
  await expect(releases).toContainText('Version 1')
  await expect(releases).toContainText(notes)

  await page.getByRole('button', { name: 'Sign out' }).click()
  await expect(page).toHaveURL('/login')

  await signIn(page, player)
  await page.goto('/play')
  const form = page.getByRole('form', { name: 'New campaign' })
  const campaign = `Campaign on ${key}`
  await form.getByLabel('Campaign name').fill(campaign)
  await form.getByLabel('Game system').selectOption(key)
  await form.getByRole('button', { name: 'Create campaign' }).click()

  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)
  await expect(page.getByRole('heading', { level: 1, name: campaign })).toBeVisible()
  await expect(page.getByText(`${name} v1`)).toBeVisible()
})
