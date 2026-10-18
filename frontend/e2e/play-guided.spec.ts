import { expect, test } from '@playwright/test'

// Users and the "Mythic-style" preset, whose default Flow guides the campaign, are seeded by
// `make e2e-seed`.
const player = 'e2e-player@example.test'
const password = process.env.E2E_PASSWORD ?? 'e2e-password-123'

test('a solo player plays the premise and the first scene check of a guided campaign', async ({
  page,
}) => {
  await page.goto('/login')
  await page.getByLabel('Email').fill(player)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL('/play')

  // A new campaign per run, along the release's default Flow.
  const form = page.getByRole('form', { name: 'New campaign' })
  await form.getByLabel('Campaign name').fill(`Guided ${String(Date.now())}`)
  await form.getByLabel('Game system').selectOption('mythic-style')
  await expect(form.getByLabel('Flow')).toHaveValue('mythic')
  await form.getByRole('button', { name: 'Create campaign' }).click()
  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)

  const guide = page.getByRole('region', { name: 'Guided play' })
  // The guide offers Start session while the flow waits for one, and "Where play stands" does not.
  await expect(guide.getByRole('button', { name: 'Start session' })).toBeVisible()
  await page.getByRole('button', { name: 'Start session' }).click()

  // The premise phase offers its one Scene Type itself: the first step waits at once.
  const premise = guide.getByRole('region', { name: 'Current step: What is the premise?' })
  await premise.getByLabel('Your answer').fill('A lighthouse keeper vanishes')
  await premise.getByRole('button', { name: 'Record' }).click()

  // The answer is in the journal, and the panel names and shows the next step.
  const journal = page.getByRole('region', { name: 'Journal' })
  await expect(journal.getByText('Flow step · What is the premise?')).toBeVisible()
  await expect(journal.getByText('A lighthouse keeper vanishes')).toBeVisible()
  await expect(guide).toContainText('Next:')

  // The first goal becomes a Thread and who is involved becomes an NPC.
  const goal = guide.getByRole('region', { name: 'Current step: What is your first goal?' })
  await goal.getByLabel('Your answer').fill('Find the keeper')
  await goal.getByRole('button', { name: 'Record' }).click()
  const involved = guide.getByRole('region', { name: 'Current step: Who is involved?' })
  await involved.getByLabel('Your answer').fill('Mara Quill')
  await involved.getByRole('button', { name: 'Record' }).click()
  await expect(page.getByRole('region', { name: 'Threads' })).toContainText('Find the keeper')
  await expect(page.getByRole('region', { name: 'NPCs' })).toContainText('Mara Quill')

  // End the premise scene: the adventure's first scene opens by itself, asking what to expect.
  await page.getByRole('button', { name: 'End scene' }).click()
  const expected = guide.getByRole('region', { name: 'Current step: What scene do you expect?' })
  await expected.getByLabel('Your answer').fill('A foggy harbour')
  await expected.getByRole('button', { name: 'Record' }).click()

  // The scene check rolls for real: any outcome moves the scene on, so assert only that the
  // check is recorded and no longer the current step.
  const check = guide.getByRole('region', { name: 'Current step: Scene check' })
  await check.getByRole('button', { name: 'Roll 1d10' }).click()
  await expect(journal.getByText('Flow step · Scene check')).toBeVisible()
  await expect(check).toBeHidden()
})
