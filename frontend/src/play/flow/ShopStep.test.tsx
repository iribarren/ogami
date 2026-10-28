import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { lostMineCampaign } from '../campaigns/fixtures'
import { atStep, guidedWithShop, promptStep, shopStep } from './fixtures'
import type { FlowStep } from './useFlowRun'

const path = `/api/campaigns/${lostMineCampaign.id}`

/** The Play screen at `step`; `answer` handles the answers and `sent` collects their bodies. */
function renderShop(answer?: FakeHandler, step: FlowStep = shopStep) {
  const sent: unknown[] = []
  renderAppAt(`/play/campaigns/${lostMineCampaign.id}`, {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${path}`]: () => Response.json(guidedWithShop(atStep(step))),
    [`GET ${path}/journal`]: () => Response.json([]),
    [`POST ${path}/flow-run/answer`]:
      answer ??
      (async (request) => {
        sent.push(await request.clone().json())
        return Response.json(guidedWithShop(atStep(promptStep)))
      }),
  })
  return sent
}

async function card() {
  return within(await screen.findByRole('region', { name: 'Current step: What do you buy?' }))
}

describe('a shop step', () => {
  it('lists the gear on sale with cost, slots, category and description, and what Vi can sell', async () => {
    renderShop()

    const step = await card()
    const buy = within(step.getByRole('region', { name: 'Buy' }))
    expect(buy.getByText('Used pistol')).toBeVisible()
    expect(buy.getByText(/50 eb/)).toBeVisible()
    expect(buy.getByText(/1 slot · Weapons/)).toBeVisible()
    expect(buy.getByText('Cheap and loud.')).toBeVisible()
    // Half of 50, rounded down; the free stack (Rope) is not offered.
    const sell = within(step.getByRole('region', { name: 'Sell' }))
    expect(sell.getByText(/pays 25 eb each/)).toBeVisible()
    expect(sell.getByText(/Pack, holds 2/)).toBeVisible()
    expect(sell.queryByText(/Rope/)).not.toBeInTheDocument()
  })

  it('shows the totals as the cart changes and sends one transaction with the Character', async () => {
    const user = userEvent.setup()
    const sent = renderShop()

    const step = await card()
    expect(step.getByRole('button', { name: 'Leave the shop' })).toBeEnabled()
    await user.type(step.getByLabelText('Buy Ration'), '2')
    await user.type(step.getByLabelText('Sell Used pistol from Pack'), '1')

    const totals = within(step.getByLabelText('Totals'))
    expect(totals.getByText('10 eb')).toBeVisible()
    expect(totals.getByText('25 eb')).toBeVisible()
    expect(totals.getByText('115 eb')).toBeVisible()
    await user.click(step.getByRole('button', { name: 'Confirm' }))

    await waitFor(() => {
      expect(sent).toEqual([
        {
          stepKey: 'stalls',
          characterId: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9001',
          buy: [{ gear: 'ration', quantity: 2 }],
          sell: [{ field: 'pack', gear: 'used-pistol', quantity: 1 }],
        },
      ])
    })
  })

  it('completes the step with an empty transaction when the cart is empty', async () => {
    const user = userEvent.setup()
    const sent = renderShop()

    await user.click((await card()).getByRole('button', { name: 'Leave the shop' }))

    await waitFor(() => {
      expect(sent).toEqual([
        {
          stepKey: 'stalls',
          characterId: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9001',
          buy: [],
          sell: [],
        },
      ])
    })
  })

  it('warns and disables Confirm when the balance would fall under the floor', async () => {
    const user = userEvent.setup()
    const sent = renderShop()

    const step = await card()
    await user.type(step.getByLabelText('Buy Used pistol'), '3')

    expect(step.getByRole('alert')).toHaveTextContent(
      'Not enough Eddies: this would leave -50 eb, under 0 eb.',
    )
    expect(step.getByRole('button', { name: 'Confirm' })).toBeDisabled()
    expect(sent).toEqual([])
  })

  it('refuses a sale of more than the stack holds, and a fractional or oversized purchase', async () => {
    const user = userEvent.setup()
    renderShop()

    const step = await card()
    await user.type(step.getByLabelText('Sell Used pistol from Pack'), '3')
    expect(step.getByRole('alert')).toHaveTextContent('Every quantity must be a whole number')
    expect(step.getByRole('button', { name: 'Confirm' })).toBeDisabled()
    await user.clear(step.getByLabelText('Sell Used pistol from Pack'))
    await user.type(step.getByLabelText('Buy Ration'), '1.5')
    expect(step.getByRole('button', { name: 'Confirm' })).toBeDisabled()
    await user.clear(step.getByLabelText('Buy Ration'))
    await user.type(step.getByLabelText('Buy Ration'), '10000')
    expect(step.getByRole('button', { name: 'Confirm' })).toBeDisabled()
  })

  it('offers no Sell section when the shop does not buy', async () => {
    renderShop(undefined, {
      ...shopStep,
      shop: shopStep.shop && { ...shopStep.shop, sellRate: null },
    })

    const step = await card()
    expect(step.getByRole('region', { name: 'Buy' })).toBeVisible()
    expect(step.queryByRole('region', { name: 'Sell' })).not.toBeInTheDocument()
  })

  it.each([
    ['insufficient_funds', 'Not enough Eddies for that.'],
    ['inventory_full', 'There is no room in the inventory for that.'],
  ])('shows a clear message for %s and keeps the cart', async (code, message) => {
    const user = userEvent.setup()
    renderShop(() => Response.json({ error: 'raw server text', code }, { status: 422 }))

    const step = await card()
    await user.type(step.getByLabelText('Buy Ration'), '2')
    await user.click(step.getByRole('button', { name: 'Confirm' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(message)
    expect(step.getByLabelText('Buy Ration')).toHaveValue(2)
  })

  it('shows the server message of any other refusal', async () => {
    const user = userEvent.setup()
    renderShop(() => Response.json({ error: 'The shop does not stock that.' }, { status: 422 }))

    const step = await card()
    await user.type(step.getByLabelText('Buy Ration'), '1')
    await user.click(step.getByRole('button', { name: 'Confirm' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('The shop does not stock that.')
  })

  it('says the answer failed on a concurrent change (409)', async () => {
    const user = userEvent.setup()
    renderShop(() => Response.json({ error: 'The campaign changed meanwhile.' }, { status: 409 }))

    await user.click((await card()).getByRole('button', { name: 'Leave the shop' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('The campaign changed meanwhile.')
  })
})
