import { expect, test } from '@playwright/test'

import { player, signIn } from './sign-in.ts'

// Users and the "West Marches" preset (SRD 5.2.1, CC BY 4.0: a founding phase, then one
// expedition per session) are seeded by `make e2e-seed`. A new Character holds 50 GP.

// The standard array fits the 72 points of the ability budget.
const spread = [
  ['Strength', '15'],
  ['Dexterity', '14'],
  ['Constitution', '13'],
  ['Intelligence', '12'],
  ['Wisdom', '10'],
  ['Charisma', '8'],
] as const

test('a solo player founds the town and a roster, opens the next session with a rumor and a party, and buys gear in town', async ({
  page,
}) => {
  await signIn(page, player, '/play')

  // The preset's credits carry the SRD attribution.
  const form = page.getByRole('form', { name: 'New campaign' })
  await form.getByLabel('Campaign name').fill(`West Marches ${String(Date.now())}`)
  await form.getByLabel('Game system').selectOption('west-marches')
  await expect(form.getByLabel('Flow')).toHaveValue('west-marches')
  const about = form.getByRole('region', { name: 'About this game' })
  await expect(about).toContainText('System Reference Document 5.2.1')
  await expect(about).toContainText('Creative Commons Attribution 4.0 International License')
  await expect(about.getByRole('link', { name: 'CC BY 4.0' })).toHaveAttribute(
    'href',
    'https://creativecommons.org/licenses/by/4.0/legalcode',
  )
  await form.getByRole('button', { name: 'Create campaign' }).click()
  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)

  await page.getByRole('button', { name: 'Start session' }).click()
  const guide = page.getByRole('region', { name: 'Focus mode' })
  const characters = page.getByRole('region', { name: 'Characters' })
  const step = (title: string) => guide.getByRole('region', { name: `Current step: ${title}` })

  // Founding, session one: the town, then two rumors.
  await page.getByRole('button', { name: 'Play Founding town' }).click()
  const town = step('What is the town called?')
  await town.getByLabel('Your answer').fill('Saltmarsh')
  await town.getByRole('button', { name: 'Record' }).click()
  await step('What is it like?').getByRole('button', { name: 'Skip' }).click()
  await step('What rumor is going around?').getByRole('button', { name: 'Roll on Rumors' }).click()
  await step('What other rumor?').getByRole('button', { name: 'Roll on Rumors' }).click()
  await expect(page.getByRole('region', { name: 'Places' })).toContainText('Saltmarsh')
  await expect(page.getByRole('region', { name: 'Threads' }).getByRole('listitem')).toHaveCount(2)
  await page.getByRole('button', { name: 'End scene' }).click()

  // The roster: three mandatory adventurers. The first form opens by itself; the next ones ask
  // for a new Character beside the ones already made.
  await page.getByRole('button', { name: 'Play Roster' }).click()
  const roster = [
    ['Who is the first adventurer?', 'Ada', 'fighter'],
    ['Who is the second?', 'Bo', 'rogue'],
    ['And the third?', 'Cy', 'wizard'],
  ] as const
  for (const [title, name, className] of roster) {
    const adventurer = step(title)
    if (name !== 'Ada') {
      await adventurer.getByRole('button', { name: 'New Character' }).click()
    }
    await adventurer.getByLabel('Name', { exact: true }).fill(name)
    await adventurer.getByLabel('Class', { exact: true }).selectOption(className)
    await adventurer.getByRole('radio', { name: 'Spend points' }).check()
    for (const [label, score] of spread) {
      await adventurer.getByLabel(label, { exact: true }).fill(score)
    }
    await adventurer.getByRole('button', { name: 'Save' }).click()
    await expect(characters).toContainText(name)
  }

  // The two suggested adventurers and the patron are optional.
  for (const title of ['Is there a fourth?', 'And a fifth?', 'Who else stands out?']) {
    await step(title).getByRole('button', { name: 'Skip' }).click()
  }
  await page.getByRole('button', { name: 'End scene' }).click()

  // The founding was the first session: it closes in town with nothing to resolve.
  await page.getByRole('button', { name: 'End session' }).click()
  await step('Safe in town. Anything worth noting?').getByRole('button', { name: 'Skip' }).click()

  // Session two opens with a rumor and a party. The party is added to the one the session
  // inherits, so everyone is still in it.
  await page.getByRole('button', { name: 'Start session' }).click()
  const rumor = step('Which rumor do you chase this session?')
  await rumor.getByRole('checkbox').first().check()
  await rumor.getByRole('button', { name: 'Choose' }).click()
  const party = step('Who joins this expedition?')
  await party.getByRole('checkbox', { name: 'Ada' }).check()
  await party.getByRole('button', { name: 'Choose' }).click()
  const partyList = page.getByRole('group', { name: 'Party' })
  await expect(partyList).toContainText('Ada')
  await expect(partyList).toContainText('Bo')
  await expect(partyList).toContainText('Cy')

  // In town, skip the talk and buy the cheapest gear Ada can afford. The stock is random.
  await page.getByRole('button', { name: 'Play Town' }).click()
  await step('What news does Saltmarsh have?').getByRole('button', { name: 'Skip' }).click()
  await step('Does anyone offer to join you?').getByRole('button', { name: 'Skip' }).click()
  await step('Is anyone ready to level up?').getByRole('button', { name: 'Skip' }).click()
  await step('Stock up on rations and torches?').getByRole('button', { name: 'Skip' }).click()

  const shop = step('What does the outfitter sell?')
  await shop.getByLabel('Character').selectOption({ label: 'Ada' })
  const rows = shop.getByRole('listitem').filter({ has: page.getByLabel(/^Buy /) })
  await expect(rows.first()).toBeVisible()
  const offers = await Promise.all(
    (await rows.all()).map(async (offer) => ({
      quantity: offer.getByLabel(/^Buy /),
      price: Number(/·\s*([\d,]+)\s*GP/.exec((await offer.textContent()) ?? '')?.[1]),
    })),
  )
  const affordable = offers
    .filter((offer) => offer.price > 0 && offer.price <= 50)
    .sort((a, b) => a.price - b.price)
  expect(affordable, 'the outfitter offers nothing Ada can afford with 50 GP').not.toHaveLength(0)
  const { quantity, price } = affordable[0]
  const item = ((await quantity.getAttribute('aria-label')) ?? '').replace(/^Buy /, '')
  await quantity.fill('1')
  const totals = shop.getByLabel('Totals')
  await expect(totals).toContainText('Gold now50 GP')
  await expect(totals).toContainText(`Gold after${String(50 - price)} GP`)
  await shop.getByRole('button', { name: 'Confirm' }).click()

  // The gold falls and the gear lands in the pack.
  await expect(characters).toContainText(`Gold: ${String(50 - price)} GP`)
  await expect(characters).toContainText(item)
})
