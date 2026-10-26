import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { lostMineCampaign } from '../campaigns/fixtures'
import { atStep, guidedWithCharacters, promptStep, tableStep } from './fixtures'
import type { FlowStep } from './useFlowRun'

const path = `/api/campaigns/${lostMineCampaign.id}`

/** The Play screen with the FlowRun waiting on `step`; `sent` collects the answers. */
function renderStep(step: FlowStep) {
  const sent: unknown[] = []
  const answer: FakeHandler = async (request) => {
    sent.push(await request.clone().json())
    return Response.json(guidedWithCharacters(atStep(promptStep)))
  }
  renderAppAt(`/play/campaigns/${lostMineCampaign.id}`, {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${path}`]: () => Response.json(guidedWithCharacters(atStep(step))),
    [`GET ${path}/journal`]: () => Response.json([]),
    [`POST ${path}/flow-run/answer`]: answer,
  })
  return sent
}

describe('a table step', () => {
  // The response names the table the step rolls now, which depends on the Scene Type (ADR 0042).
  it.each([
    ['weather', 'Roll on Weather'],
    ['action', 'Roll on Action'],
  ])('offers the roll of the table the step names: %s', async (table, label) => {
    const user = userEvent.setup()
    const sent = renderStep({ ...tableStep, table })

    const step = within(
      await screen.findByRole('region', { name: `Current step: ${tableStep.title}` }),
    )
    await user.click(step.getByRole('button', { name: label }))

    await waitFor(() => {
      expect(sent).toEqual([{ stepKey: tableStep.key }])
    })
  })
})
