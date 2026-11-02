import { expect, test, type Locator, type Page } from '@playwright/test'

import { player, signIn } from './sign-in.ts'

// Users and the "Ironsworn (unofficial)" preset (Ironsworn SRD, CC BY 4.0: a prologue, then a
// loop of moves) are seeded by `make e2e-seed`. The dice are random, so every roll is followed
// to its end whatever it showed; the flow rules are covered by IronswornPresetFlowTest.

/**
 * Follows a roll to the point where the scene can end: keeps a burn offer, and answers the
 * obstacle of a missed oath or the cost of a weak hit with a choice that changes the sheet.
 */
async function settle(page: Page, guide: Locator) {
  const keep = page.getByRole('button', { name: 'Keep' })
  const obstacle = guide.getByRole('region', { name: 'Current step: What stands in your way?' })
  const cost = guide.getByRole('region', { name: 'Current step: What does it cost?' })
  const end = page.getByRole('button', { name: 'End scene' })
  for (let attempt = 0; attempt < 4; attempt += 1) {
    await expect(keep.or(obstacle).or(cost).or(end)).toBeVisible()
    if (await end.isVisible()) {
      return
    }
    if (await keep.isVisible()) {
      await keep.click()
      await expect(keep).toBeHidden()
    } else {
      const open = (await obstacle.isVisible()) ? obstacle : cost
      await open
        .getByRole('button', { name: obstacle === open ? 'Press on' : 'Lose momentum' })
        .click()
      await open.getByRole('button', { name: 'Confirm' }).click()
      await expect(open).toBeHidden()
    }
  }
  await expect(end).toBeVisible()
}

test('a solo player sets out in the Ironlands, swears two vows and rolls a move against the challenge dice', async ({
  page,
}) => {
  await signIn(page, player, '/play')

  // The preset's credits carry the licence notice and say that it is unofficial.
  const form = page.getByRole('form', { name: 'New campaign' })
  await form.getByLabel('Campaign name').fill(`Ironsworn ${String(Date.now())}`)
  await form.getByLabel('Game system').selectOption('ironsworn')
  await expect(form.getByLabel('Flow')).toHaveValue('ironsworn')
  const about = form.getByRole('region', { name: 'About this game' })
  await expect(about).toContainText('Creative Commons Attribution 4.0 International License')
  await expect(about).toContainText('Unofficial')
  await expect(about.getByRole('link', { name: 'CC BY 4.0' })).toHaveAttribute(
    'href',
    'https://creativecommons.org/licenses/by/4.0/',
  )
  await form.getByRole('button', { name: 'Create campaign' }).click()
  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)

  await page.getByRole('button', { name: 'Start session' }).click()
  const guide = page.getByRole('region', { name: 'Focus mode' })
  const step = (title: string) => guide.getByRole('region', { name: `Current step: ${title}` })
  const endScene = page.getByRole('button', { name: 'End scene' })
  const swear = async (vow: string, rank: string, slot: string) => {
    const oath = step('What do you swear to do?')
    await oath.getByLabel('Your answer').fill(vow)
    await oath.getByRole('button', { name: 'Record' }).click()
    await step('How formidable is the quest?').getByRole('button', { name: rank }).click()
    await step('Which slot holds it?').getByRole('button', { name: slot }).click()
  }

  // The place, then the Character with the stat budget of 9 points (3, 2, 2, 1, 1).
  await page.getByRole('button', { name: 'Play The Ironlands' }).click()
  const place = step('Where does your story begin?')
  await place.getByLabel('Your answer').fill('Hearth')
  await place.getByRole('button', { name: 'Record' }).click()
  await step('What is the land like?').getByRole('button', { name: 'Skip' }).click()
  await step('What trouble brings you here?').getByRole('button', { name: 'Skip' }).click()
  await endScene.click()

  await page.getByRole('button', { name: 'Play Ironsworn' }).click()
  const hero = step('Who are you?')
  await hero.getByLabel('Name', { exact: true }).fill('Aldis')
  for (const [stat, points] of [
    ['Edge', '3'],
    ['Heart', '2'],
    ['Iron', '2'],
    ['Shadow', '1'],
    ['Wits', '1'],
  ]) {
    await hero.getByLabel(stat, { exact: true }).fill(points)
  }
  await expect(hero).toContainText('Stat points: 9 / 9')
  await hero.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByRole('region', { name: 'Characters' })).toContainText('Aldis')
  await step('Who is your first bond?').getByLabel('Your answer').fill('Mara')
  await step('Who is your first bond?').getByRole('button', { name: 'Record' }).click()
  for (const title of ['Who is your second bond?', 'Who is your third bond?']) {
    await step(title).getByRole('button', { name: 'Skip' }).click()
  }
  await endScene.click()

  // A background vow takes no roll; the inciting vow rolls heart, which may need an answer.
  await page.getByRole('button', { name: 'Play Swear a vow' }).click()
  await swear('Find the lost bell', 'Troublesome', 'Vow 1')
  await step('Swear upon iron').getByRole('button', { name: 'Skip' }).click()
  await endScene.click()

  await page.getByRole('button', { name: 'Play Swear a vow' }).click()
  await swear('Avenge my brother', 'Dangerous', 'Vow 2')
  await step('Swear upon iron').getByRole('button', { name: 'Roll' }).click()
  await settle(page, guide)
  await endScene.click()

  // The prologue is done: a move rolls an action die and a stat against two challenge dice.
  await page.getByRole('button', { name: 'Play Face danger' }).click()
  const danger = step('What do you do?')
  await danger.getByLabel('Your answer').fill('Cross the rope bridge')
  await danger.getByRole('button', { name: 'Record' }).click()
  const roll = step('Face the danger')
  await roll.getByLabel('Check').selectOption({ label: 'Edge' })
  await roll.getByRole('button', { name: 'Roll' }).click()
  await settle(page, guide)

  // Whatever the dice said, the journal shows both, the score and the band.
  await page.getByRole('radio', { name: 'Journal view' }).check()
  const journal = page.getByRole('region', { name: 'Journal' })
  await expect(journal).toContainText('Edge')
  await expect(
    journal.getByRole('list', { name: 'Challenge dice' }).last().getByRole('listitem'),
  ).toHaveCount(2)
  await expect(journal.getByText('Score').last()).toBeVisible()
  await expect(journal).toContainText(/Strong hit|Weak hit|Miss/)
})
