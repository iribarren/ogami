import { expect, test } from '@playwright/test'

import { player, signIn } from './sign-in.ts'

// Users and the "Mythic-style" preset, whose default Flow guides the campaign, are seeded by
// `make e2e-seed`.

test('a solo player plays from the table and finds the same campaign on the Play page', async ({
  page,
}) => {
  // Wide enough for the table's desktop layout: the dice tray lies open at the edge.
  await page.setViewportSize({ width: 1440, height: 900 })
  await signIn(page, player, '/play')

  const form = page.getByRole('form', { name: 'New campaign' })
  await form.getByLabel('Campaign name').fill(`Table ${String(Date.now())}`)
  await form.getByLabel('Game system').selectOption('mythic-style')
  await form.getByRole('button', { name: 'Create campaign' }).click()
  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)
  await page.getByRole('button', { name: 'Start session' }).click()
  await expect(page.getByRole('region', { name: 'Guided play' })).toContainText('Next:')

  // A mark on the window survives a client-side navigation only: a reload would lose it.
  await page.evaluate(() => {
    Object.assign(window, { tableMark: 'no reload' })
  })

  // The header button opens the table of the same campaign.
  await page.getByRole('link', { name: 'Table' }).click()
  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}\/table$/)

  // The premise phase offers its one Scene Type itself: the current scene's card opens the panel.
  await page.getByRole('button', { name: /^Open scene/ }).click()
  const panel = page.getByRole('dialog', { name: /^Scene:/ })
  const premise = panel.getByRole('region', { name: 'Current step: What is the premise?' })
  await premise.getByLabel('Your answer').fill('A lighthouse keeper vanishes')
  await premise.getByRole('button', { name: 'Record' }).click()
  await expect(panel).toContainText('Next:')
  await page.keyboard.press('Escape')
  await expect(panel).toBeHidden()

  // The dice tray throws and records a roll.
  const tray = page.getByRole('region', { name: 'Dice tray' })
  await tray.getByRole('button', { name: 'Roll 2d6' }).click()

  // The notebook opens full screen: this scene's answer is there, and a note is written in it.
  await page.getByRole('button', { name: 'Open the notebook' }).click()
  const notebook = page.getByRole('dialog', { name: 'Notebook' })
  await expect(notebook.getByText('A lighthouse keeper vanishes')).toBeVisible()
  await notebook.getByRole('textbox', { name: 'New note' }).fill('Written at the table')
  await notebook.getByRole('button', { name: 'Write note' }).click()
  await expect(notebook.getByText('Written at the table')).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(notebook).toBeHidden()

  // Back on the Play page: the same FlowRun and journal, with no reload.
  await page.getByRole('link', { name: 'Back to the Play page' }).click()
  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)
  const journal = page.getByRole('region', { name: 'Journal' })
  await expect(journal.getByText('A lighthouse keeper vanishes')).toBeVisible()
  await expect(journal.getByText('Written at the table')).toBeVisible()
  await expect(page.getByRole('region', { name: 'Guided play' })).toContainText('Next:')
  expect(await page.evaluate(() => (window as { tableMark?: string }).tableMark)).toBe('no reload')
})
