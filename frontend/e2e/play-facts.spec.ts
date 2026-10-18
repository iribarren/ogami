import { expect, test, type Page } from '@playwright/test'

// Users and the "Free journal" preset are seeded by `make e2e-seed`. No preset declares Fact slots
// yet, so the smoke covers free facts and their links only.
const player = 'e2e-player@example.test'
const password = process.env.E2E_PASSWORD ?? 'e2e-password-123'

async function signIn(page: Page) {
  await page.goto('/login')
  await page.getByLabel('Email').fill(player)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL('/play')
}

test('a solo player keeps free facts, edits one and links it to an NPC', async ({ page }) => {
  await signIn(page)

  // A new campaign per run: the dev database keeps the campaigns of earlier runs.
  const form = page.getByRole('form', { name: 'New campaign' })
  await form.getByLabel('Campaign name').fill(`Facts ${String(Date.now())}`)
  await form.getByLabel('Game system').selectOption('free-journal')
  await form.getByRole('button', { name: 'Create campaign' }).click()
  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)

  const facts = page.getByRole('region', { name: 'Facts' })
  const npcs = page.getByRole('region', { name: 'NPCs' })
  await expect(facts).toContainText('No facts yet.')

  // Add a free fact.
  await facts.getByRole('button', { name: 'Add fact' }).click()
  await facts.getByLabel('Label').fill('The guild')
  await facts.getByLabel('Text').fill('Owes a debt to the Prince.')
  await facts.getByRole('button', { name: 'Save' }).click()
  await expect(facts).toContainText('Owes a debt to the Prince.')

  // Edit its text.
  await facts.getByRole('button', { name: 'Edit The guild' }).click()
  await facts.getByLabel('Text').fill('Owes two debts to the Prince.')
  await facts.getByRole('button', { name: 'Save' }).click()
  await expect(facts).toContainText('Owes two debts to the Prince.')

  // Link it to an NPC: the NPC row lists the fact.
  await npcs.getByRole('button', { name: 'Add NPC' }).click()
  await npcs.getByLabel('Name').fill('Mira Vane')
  await npcs.getByRole('button', { name: 'Save' }).click()
  await expect(npcs).toContainText('Mira Vane')
  await facts.getByRole('button', { name: 'Edit links of The guild' }).click()
  await facts.getByLabel('Link to').selectOption({ label: 'NPC: Mira Vane' })
  await facts.getByRole('button', { name: 'Add link' }).click()
  await facts.getByRole('button', { name: 'Save' }).click()
  await expect(facts).toContainText('Links: Mira Vane (NPC)')
  await expect(npcs).toContainText('Facts: The guild')

  // The facts are saved: a reload shows the same.
  await page.reload()
  await expect(page.getByRole('region', { name: 'Facts' })).toContainText('Owes two debts')
})
