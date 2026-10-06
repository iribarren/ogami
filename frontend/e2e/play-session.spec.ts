import { expect, test, type Page } from '@playwright/test'

// Users and the "Free journal" preset are seeded by `make e2e-seed`.
const player = 'e2e-player@example.test'
const password = process.env.E2E_PASSWORD ?? 'e2e-password-123'

async function signIn(page: Page) {
  await page.goto('/login')
  await page.getByLabel('Email').fill(player)
  await page.getByLabel('Password').fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL('/play')
}

test('a solo player plays a free-journal session end to end', async ({ page }) => {
  await signIn(page)

  // A new campaign per run: the dev database keeps the campaigns of earlier runs.
  const form = page.getByRole('form', { name: 'New campaign' })
  await form.getByLabel('Campaign name').fill(`Free play ${String(Date.now())}`)
  await form.getByLabel('GameSystem').selectOption('free-journal')
  await form.getByRole('button', { name: 'Create campaign' }).click()
  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)

  // Nothing can be recorded before a scene.
  const position = page.getByRole('region', { name: 'Where play stands' })
  await expect(position).toContainText('No session yet')
  await expect(page.getByLabel('New note')).toBeDisabled()
  await expect(page.getByLabel('Dice expression')).toBeDisabled()

  await page.getByRole('button', { name: 'Start session' }).click()
  await expect(position).toContainText('Session 1 · No scene yet')
  await page.getByLabel('Scene title').fill('At the gate')
  await page.getByRole('button', { name: 'Start scene' }).click()
  await expect(position).toContainText('Session 1 · Scene 1: At the gate')

  const scene = page
    .getByRole('region', { name: 'Journal' })
    .getByRole('region', { name: 'Session 1' })
    .getByRole('region', { name: 'Scene 1 — At the gate' })
  await expect(scene).toContainText('Nothing recorded in this scene yet.')

  // A note.
  await page.getByLabel('New note').fill('The gate is open.')
  await page.getByRole('button', { name: 'Write note' }).click()
  await expect(scene.getByRole('article', { name: 'Note' })).toContainText('The gate is open.')

  // A roll: two six-sided dice plus one, rolled on the server.
  await page.getByLabel('Dice expression').fill('2d6+1')
  await page.getByRole('button', { name: 'Roll', exact: true }).click()
  const roll = scene.getByRole('article', { name: 'Roll 2d6+1' })
  await expect(roll).toBeVisible()
  const faces = (
    await roll.getByRole('list', { name: '2d6 dice' }).getByRole('listitem').allTextContents()
  ).map(Number)
  expect(faces).toHaveLength(2)
  for (const face of faces) {
    expect(face).toBeGreaterThanOrEqual(1)
    expect(face).toBeLessThanOrEqual(6)
  }
  const total = await roll.locator('[aria-describedby]').textContent()
  expect(Number(total)).toBe(faces.reduce((sum, face) => sum + face, 1))

  // An oracle table of the pinned release.
  await page.getByRole('button', { name: 'Roll on Action' }).click()
  const action = scene.getByRole('article', { name: 'Oracle Action' })
  await expect(action.getByRole('list', { name: 'Oracle table steps' })).toContainText('Action')

  // A likelihood oracle without chaos factor.
  const question = page.getByRole('form', { name: 'Yes/no question' })
  await expect(question.getByLabel('Chaos factor')).toHaveCount(0)
  await question.getByLabel('Question (optional)').fill('Is anyone home?')
  await question.getByLabel('Likelihood').selectOption({ label: 'Likely' })
  await question.getByRole('button', { name: 'Ask' }).click()
  const answer = scene.getByRole('article', { name: 'Oracle Yes/no question' })
  await expect(answer).toContainText('“Is anyone home?”')
  await expect(answer.getByText(/^(Exceptional yes|Yes|No|Exceptional no)$/)).toBeVisible()
  await expect(answer).toContainText('LikelihoodLikely')

  // The journal is saved: a reload shows the same entries, in order.
  await page.reload()
  await expect(scene.getByRole('article')).toHaveCount(4)
  const entries = await scene.getByRole('article').all()
  expect(await Promise.all(entries.map((entry) => entry.getAttribute('aria-label')))).toEqual([
    'Note',
    'Roll 2d6+1',
    'Oracle Action',
    'Oracle Yes/no question',
  ])
})
