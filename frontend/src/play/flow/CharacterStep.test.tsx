import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { lostMineCampaign } from '../campaigns/fixtures'
import type { Campaign } from '../campaigns/useCampaigns'
import { viCharacter } from '../characters/fixtures'
import { atStep, characterStep, guidedWithCharacters, promptStep } from './fixtures'
import type { FlowStep } from './useFlowRun'

const path = `/api/campaigns/${lostMineCampaign.id}`
const afterStep = guidedWithCharacters(atStep(promptStep))

/** The Play screen with the FlowRun waiting on `step`; `sent` collects the bodies by route. */
function renderStep(
  step: FlowStep,
  handlers: Record<string, FakeHandler> = {},
  campaign: Campaign = guidedWithCharacters(atStep(step)),
) {
  const sent: Record<string, unknown[]> = {}
  const routes: Record<string, FakeHandler> = {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${path}`]: () => Response.json(campaign),
    [`GET ${path}/journal`]: () => Response.json([]),
  }
  for (const [route, handler] of Object.entries(handlers)) {
    routes[`POST ${path}/${route}`] = async (request) => {
      sent[route] = [...(sent[route] ?? []), await request.clone().json()]
      return handler(request)
    }
  }
  return { ...renderAppAt(`/play/campaigns/${lostMineCampaign.id}`, routes), sent }
}

async function card(title = characterStep.title) {
  return within(await screen.findByRole('region', { name: `Current step: ${title}` }))
}

const answering = (next: Campaign): FakeHandler => {
  return () => Response.json(next)
}

describe('a character step', () => {
  it('lists the Characters, completes with the one picked and cannot without one', async () => {
    const user = userEvent.setup()
    const { sent } = renderStep(characterStep, { 'flow-run/answer': answering(afterStep) })

    const step = await card()
    expect(step.getByRole('button', { name: 'Choose' })).toBeDisabled()
    await user.click(step.getByRole('radio', { name: 'Vi' }))
    await user.click(step.getByRole('button', { name: 'Choose' }))

    await waitFor(() => {
      expect(sent['flow-run/answer']).toEqual([{ stepKey: 'crew', characterId: viCharacter.id }])
    })
    expect(await screen.findByRole('region', { name: 'Current step: Who walks in?' })).toBeVisible()
  })

  it('shows the form instead when the campaign has no Characters yet', async () => {
    renderStep(
      characterStep,
      {},
      { ...guidedWithCharacters(atStep(characterStep)), characters: [] },
    )

    const step = await card()
    expect(step.queryByRole('radio')).not.toBeInTheDocument()
    expect(step.getByLabelText('Name')).toBeInTheDocument()
    expect(step.getByRole('group', { name: 'Stats' })).toBeInTheDocument()
    expect(step.queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument()
  })

  it('creates a Character from the step, then completes the step with its id', async () => {
    const user = userEvent.setup()
    const { sent } = renderStep(
      characterStep,
      {
        characters: () => Response.json(viCharacter, { status: 201 }),
        'flow-run/answer': answering(afterStep),
      },
      { ...guidedWithCharacters(atStep(characterStep)), characters: [] },
    )

    const step = await card()
    await user.type(step.getByLabelText('Name'), 'Vi')
    await user.click(step.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(sent['flow-run/answer']).toEqual([{ stepKey: 'crew', characterId: viCharacter.id }])
    })
    expect(sent.characters).toEqual([expect.objectContaining({ name: 'Vi' })])
  })

  it('offers a new Character next to the existing ones, and goes back', async () => {
    const user = userEvent.setup()
    renderStep(characterStep)

    const step = await card()
    await user.click(step.getByRole('button', { name: 'New Character' }))
    expect(step.getByLabelText('Name')).toBeInTheDocument()
    expect(step.queryByRole('radio')).not.toBeInTheDocument()
    await user.click(step.getByRole('button', { name: 'Cancel' }))
    expect(step.getByRole('radio', { name: 'Vi' })).toBeInTheDocument()
  })

  it('leaves the step uncompleted when creating the Character fails, with the reason', async () => {
    const user = userEvent.setup()
    const { sent } = renderStep(
      characterStep,
      {
        characters: () =>
          Response.json({ error: 'The Character name is required.' }, { status: 422 }),
        'flow-run/answer': answering(afterStep),
      },
      { ...guidedWithCharacters(atStep(characterStep)), characters: [] },
    )

    const step = await card()
    await user.type(step.getByLabelText('Name'), 'Vi')
    await user.click(step.getByRole('button', { name: 'Save' }))

    expect(await step.findByRole('alert')).toHaveTextContent('The Character name is required.')
    expect(sent['flow-run/answer']).toBeUndefined()
    expect(step.getByLabelText('Name')).toHaveValue('Vi')
  })

  it('keeps the created Character pickable when completing the step is refused, never creating twice', async () => {
    const user = userEvent.setup()
    let answers = 0
    const { sent } = renderStep(
      characterStep,
      {
        characters: () => Response.json(viCharacter, { status: 201 }),
        'flow-run/answer': () => {
          answers += 1
          return answers === 1
            ? Response.json({ error: 'The step cannot be completed.' }, { status: 422 })
            : Response.json(afterStep)
        },
      },
      { ...guidedWithCharacters(atStep(characterStep)), characters: [] },
    )

    const step = await card()
    await user.type(step.getByLabelText('Name'), 'Vi')
    await user.click(step.getByRole('button', { name: 'Save' }))

    expect(await screen.findByText('The step cannot be completed.')).toBeInTheDocument()
    expect(step.queryByLabelText('Name')).not.toBeInTheDocument()
    expect(step.getByRole('radio', { name: 'Vi' })).toBeChecked()
    await user.click(step.getByRole('button', { name: 'Choose' }))

    await waitFor(() => {
      expect(sent['flow-run/answer']).toEqual([
        { stepKey: 'crew', characterId: viCharacter.id },
        { stepKey: 'crew', characterId: viCharacter.id },
      ])
    })
    expect(sent.characters).toHaveLength(1)
  })

  it("shows the API's reason when the step is refused", async () => {
    const user = userEvent.setup()
    renderStep(characterStep, {
      'flow-run/answer': () => Response.json({ error: 'Character not found.' }, { status: 422 }),
    })

    const step = await card()
    await user.click(step.getByRole('radio', { name: 'Vi' }))
    await user.click(step.getByRole('button', { name: 'Choose' }))

    expect(await screen.findByText('Character not found.')).toBeInTheDocument()
    expect(step.getByRole('button', { name: 'Choose' })).toBeEnabled()
  })

  it('offers Skip only when the step is not mandatory', async () => {
    renderStep({ ...characterStep, mandatory: false })
    expect((await card()).getByRole('button', { name: 'Skip' })).toBeInTheDocument()
  })

  it('has no Skip when the step is mandatory', async () => {
    renderStep(characterStep)
    expect((await card()).queryByRole('button', { name: 'Skip' })).not.toBeInTheDocument()
  })
})
