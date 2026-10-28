import { screen, within } from '@testing-library/react'

import { renderAppAt, signedInAs } from '@/test/renderApp'

import { lostMineCampaign } from '../campaigns/fixtures'
import { atStep, guidedWithCharacters, promptStep } from './fixtures'
import type { FlowStep } from './useFlowRun'

const path = `/api/campaigns/${lostMineCampaign.id}`

/** A shop step (ADR 0045) selling a knife; the SPA has no cart for it yet. */
const shopStep: FlowStep = {
  ...promptStep,
  key: 'stalls',
  kind: 'shop',
  title: 'What do you buy?',
  prompt: null,
  tip: null,
  shop: {
    onSale: [
      { key: 'knife', name: 'Knife', cost: 10, slots: 1, category: 'Gear', description: null },
    ],
    sellRate: 50,
    currency: { field: 'gold', unit: 'eb' },
  },
}

describe('a shop step', () => {
  it('shows its card without a control to complete it', async () => {
    renderAppAt(`/play/campaigns/${lostMineCampaign.id}`, {
      'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
      [`GET ${path}`]: () => Response.json(guidedWithCharacters(atStep(shopStep))),
      [`GET ${path}/journal`]: () => Response.json([]),
    })

    const step = within(
      await screen.findByRole('region', { name: 'Current step: What do you buy?' }),
    )
    expect(step.getByText('Shop')).toBeVisible()
    expect(step.getByText('This shop cannot be played here yet.')).toBeVisible()
  })
})
