import { expect, test } from '@playwright/test'

import { player, signIn } from './sign-in.ts'

// Users and the "Night Job" preset, a fan preset with credits and a Flow introduction, are seeded
// by `make e2e-seed`.

test('a solo player sees the disclaimer and the introduction, creates their Character, then completes the crew step', async ({
  page,
}) => {
  await signIn(page, player, '/play')

  // Choosing the preset shows the publisher's disclaimer and what the Flow is about.
  const form = page.getByRole('form', { name: 'New campaign' })
  await form.getByLabel('Campaign name').fill(`Night Job ${String(Date.now())}`)
  await form.getByLabel('Game system').selectOption('night-job')
  await expect(form.getByLabel('Flow')).toHaveValue('heist')
  const about = form.getByRole('region', { name: 'About this game' })
  await expect(about).toContainText('Night Job is unofficial content provided under the Homebrew')
  await expect(about).toContainText('not approved or endorsed by RTG')
  await expect(
    about.getByRole('link', { name: 'R. Talsorian Games Homebrew Content Policy' }),
  ).toHaveAttribute('href', 'https://rtalsoriangames.com/homebrew-content-policy/')
  await expect(form).toContainText('Solo play means you are the whole table')
  await form.getByRole('button', { name: 'Create campaign' }).click()
  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)

  // The disclaimer stays on the campaign page.
  await expect(page.getByRole('region', { name: 'About this game' })).toContainText(
    'not approved or endorsed by RTG',
  )

  // The job starts with two Scene Types in sequence: pick the crew, then answer its mandatory step.
  await page.getByRole('button', { name: 'Start session' }).click()
  await page.getByRole('button', { name: 'Play Crew' }).click()
  // The Flow opens in the focus view, which holds the current step.
  const guide = page.getByRole('region', { name: 'Focus mode' })

  // Session Zero starts with the player's own Character: no Character exists yet, so the step shows the sheet.
  const you = guide.getByRole('region', { name: 'Current step: Who are you?' })
  await expect(you.getByRole('group', { name: 'Stats' })).toBeVisible()
  await you.getByLabel('Name').fill('Vi')
  await you.getByLabel('Role').fill('Netrunner')
  await you.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByRole('region', { name: 'Characters' })).toContainText('Vi')
  // The Character joins the party and the scene's cast.
  await expect(page.getByRole('group', { name: 'Party' })).toContainText('Vi')
  await expect(guide.getByRole('group', { name: /^Cast of session/ })).toContainText('Vi')

  const first = guide.getByRole('region', { name: 'Current step: Who is your first crew member?' })
  await first.getByLabel('Your answer').fill('Ada, a netrunner')
  await first.getByRole('button', { name: 'Record' }).click()

  // The crew member joins the NPCs, and the guide moves to the next, suggested step.
  await expect(page.getByRole('region', { name: 'NPCs' })).toContainText('Ada, a netrunner')
  await expect(guide.getByRole('region', { name: 'Current step: Who else is in?' })).toBeVisible()
})
