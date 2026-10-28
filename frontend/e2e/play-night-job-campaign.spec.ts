import { expect, test } from '@playwright/test'

import { player, signIn } from './sign-in.ts'

// Users and the "Night Job" preset (release v2: the heist flow and the "Making a Name" campaign
// flow, with eddies, inventories and a Night Market) are seeded by `make e2e-seed`.

test('a solo player starts the campaign flow, plays street zero, then buys something at the Night Market', async ({
  page,
}) => {
  await signIn(page, player, '/play')

  // The campaign flow is not the default: pick it in the New campaign form.
  const form = page.getByRole('form', { name: 'New campaign' })
  await form.getByLabel('Campaign name').fill(`Making a name ${String(Date.now())}`)
  await form.getByLabel('Game system').selectOption('night-job')
  await expect(form.getByLabel('Flow')).toHaveValue('heist')
  await form.getByLabel('Flow').selectOption('campaign')
  await form.getByRole('button', { name: 'Create campaign' }).click()
  await expect(page).toHaveURL(/\/play\/campaigns\/[0-9a-f-]{36}$/)

  await page.getByRole('button', { name: 'Start session' }).click()
  const guide = page.getByRole('region', { name: 'Focus mode' })
  const characters = page.getByRole('region', { name: 'Characters' })

  // Street zero, in sequence. The Edgerunner scene makes the Character, who starts with 500 eb.
  await page.getByRole('button', { name: 'Play Edgerunner' }).click()
  const you = guide.getByRole('region', { name: 'Current step: Who are you?' })
  await you.getByLabel('Name', { exact: true }).fill('Vi')
  await you.getByLabel('Role').fill('Netrunner')
  await you.getByRole('button', { name: 'Save' }).click()
  await expect(characters).toContainText('Vi')
  await expect(characters).toContainText('Eddies: 500 eb')
  await page.getByRole('button', { name: 'End scene' }).click()

  // Turf: two prompts that fill the Facts.
  await page.getByRole('button', { name: 'Play Turf' }).click()
  const block = guide.getByRole('region', { name: 'Current step: Which block is your turf?' })
  await block.getByLabel('Your answer').fill('Neon Row')
  await block.getByRole('button', { name: 'Record' }).click()
  const crew = guide.getByRole('region', { name: 'Current step: What do you call your crew?' })
  await crew.getByLabel('Your answer').fill('The Static')
  await crew.getByRole('button', { name: 'Record' }).click()
  await page.getByRole('button', { name: 'End scene' }).click()

  // Fixer: the answer becomes an NPC.
  await page.getByRole('button', { name: 'Play Fixer' }).click()
  const fixer = guide.getByRole('region', { name: 'Current step: Who is your fixer?' })
  await fixer.getByLabel('Your answer').fill('Mama Lux')
  await fixer.getByRole('button', { name: 'Record' }).click()
  await expect(page.getByRole('region', { name: 'NPCs' })).toContainText('Mama Lux')
  await expect(page.getByRole('region', { name: 'Facts' })).toContainText('Neon Row')
  await expect(page.getByRole('region', { name: 'Facts' })).toContainText('The Static')
  await page.getByRole('button', { name: 'End scene' }).click()

  // Act 1: the player picks the Scene Types. Downtime: skip the free prompt, then end the scene.
  await page.getByRole('button', { name: 'Play Downtime' }).click()
  await guide
    .getByRole('region', { name: 'Current step: How do you spend the downtime?' })
    .getByRole('button', { name: 'Skip' })
    .click()
  await page.getByRole('button', { name: 'End scene' }).click()

  // The week passes (the Month clock moves), then the Night Market is offered.
  const days = guide.getByRole('region', { name: 'Current step: A week slips by. What changed?' })
  await days.getByLabel('Your answer').fill('Lying low')
  await days.getByRole('button', { name: 'Record' }).click()
  const market = guide.getByRole('region', { name: 'Current step: Hit the Night Market?' })
  await market.getByRole('button', { name: 'Yes' }).click()

  // Act 1's world turn runs before the market scene is offered. Its roll is random: roll it, and
  // if the street stirs, skip whatever event steps follow (the market stays the forced next scene).
  const playMarket = page.getByRole('button', { name: 'Play Night Market' })
  const rollStreet = guide
    .getByRole('region', { name: 'Current step: Does the street stir?' })
    .getByRole('button', { name: 'Roll 1d6' })
  const skipEvent = guide
    .getByRole('region', { name: /^Current step: (?!Does the street stir)/ })
    .getByRole('button', { name: 'Skip' })
  const worldStep = guide.getByRole('region', { name: /^Current step:/ })
  for (let turn = 0; turn < 8; turn++) {
    await expect(worldStep.or(playMarket)).toBeVisible()
    if (await playMarket.isVisible()) {
      break
    }
    const name = (await worldStep.getAttribute('aria-label')) ?? ''
    await rollStreet.or(skipEvent).click()
    await expect(guide.getByRole('region', { name, exact: true })).toBeHidden()
  }

  // The stock and its prices are random too: buy the cheapest item on offer that the 500 eb cover.
  await playMarket.click()
  await guide
    .getByRole('region', { name: 'Current step: What does the market look like tonight?' })
    .getByRole('button', { name: 'Skip' })
    .click()
  const shop = guide.getByRole('region', { name: 'Current step: What do you buy or sell?' })
  const rows = shop.getByRole('listitem').filter({ has: page.getByLabel(/^Buy /) })
  await expect(rows.first()).toBeVisible()
  const offers = await Promise.all(
    (await rows.all()).map(async (offer) => ({
      quantity: offer.getByLabel(/^Buy /),
      price: Number(
        /·\s*([\d,]+)\s*eb/.exec((await offer.textContent()) ?? '')?.[1].replace(',', ''),
      ),
    })),
  )
  const affordable = offers
    .filter((offer) => offer.price > 0 && offer.price <= 500)
    .sort((a, b) => a.price - b.price)
  expect(affordable, 'the stock offers nothing Vi can afford with 500 eb').not.toHaveLength(0)
  const { quantity, price } = affordable[0]
  const item = ((await quantity.getAttribute('aria-label')) ?? '').replace(/^Buy /, '')
  await quantity.fill('1')
  const totals = shop.getByLabel('Totals')
  await expect(totals).toContainText('Eddies now500 eb')
  await expect(totals).toContainText(`Eddies after${(500 - price).toLocaleString('en-US')} eb`)
  await shop.getByRole('button', { name: 'Confirm' }).click()

  // The price comes off the 500 eb and the item is on the sheet.
  await expect(characters).toContainText(`Eddies: ${(500 - price).toLocaleString('en-US')} eb`)
  await expect(characters).toContainText(item)
})
