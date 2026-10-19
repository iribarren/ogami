import { expect, test } from '@playwright/test'

import { player, signIn } from './sign-in.ts'

// Users and the "Free journal" preset are seeded by `make e2e-seed`.

test('a solo player keeps NPCs and Threads, edits them, and closes and reopens one', async ({
  page,
}) => {
  await signIn(page, player, '/play')

  // A new campaign per run: the dev database keeps the campaigns of earlier runs.
  const form = page.getByRole('form', { name: 'New campaign' })
  await form.getByLabel('Campaign name').fill(`Lists ${String(Date.now())}`)
  await form.getByLabel('Game system').selectOption('free-journal')
  await form.getByRole('button', { name: 'Create campaign' }).click()
  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)

  const npcs = page.getByRole('region', { name: 'NPCs' })
  const threads = page.getByRole('region', { name: 'Threads' })
  await expect(npcs).toContainText('No open NPCs yet.')
  await expect(threads).toContainText('No open Threads yet.')

  // Add an NPC with an agenda.
  await npcs.getByRole('button', { name: 'Add NPC' }).click()
  await npcs.getByLabel('Name').fill('Mira Vane')
  await npcs.getByLabel('Agenda').fill('Wants the ledger back.')
  await npcs.getByRole('button', { name: 'Save' }).click()
  await expect(npcs).toContainText('Mira Vane')
  await expect(npcs).toContainText('Wants the ledger back.')

  // Add a Thread.
  await threads.getByRole('button', { name: 'Add Thread' }).click()
  await threads.getByLabel('Name').fill('Find the ledger')
  await threads.getByRole('button', { name: 'Save' }).click()
  await expect(threads).toContainText('Find the ledger')

  // Edit the NPC's name.
  await npcs.getByRole('button', { name: 'Edit Mira Vane' }).click()
  await npcs.getByLabel('Name').fill('Mira Voss')
  await npcs.getByRole('button', { name: 'Save' }).click()
  await expect(npcs).toContainText('Mira Voss')
  await expect(npcs).not.toContainText('Mira Vane')

  // Close the Thread: it moves under Closed, then comes back open.
  await threads.getByRole('button', { name: 'Close Find the ledger' }).click()
  await expect(threads).toContainText('No open Threads yet.')
  await threads.getByText('Closed (1)').click()
  await threads.getByRole('button', { name: 'Reopen Find the ledger' }).click()
  await expect(threads.getByRole('button', { name: 'Close Find the ledger' })).toBeVisible()
  await expect(threads).not.toContainText('Closed (1)')

  // The lists are saved: a reload shows the same items.
  await page.reload()
  await expect(page.getByRole('region', { name: 'NPCs' })).toContainText('Mira Voss')
  await expect(page.getByRole('region', { name: 'Threads' })).toContainText('Find the ledger')
})
