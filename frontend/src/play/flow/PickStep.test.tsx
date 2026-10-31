import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { lostMineCampaign } from '../campaigns/fixtures'
import type { Campaign } from '../campaigns/useCampaigns'
import { viCharacter } from '../characters/fixtures'
import {
  atStep,
  chooseCharacterStep,
  chooseOneStep,
  choosePlaceStep,
  chooseTwoStep,
  chooseUpToTwoStep,
  guidedWithLists,
  noMatchCharacterStep,
  noMatchChooseStep,
  noMatchRandomStep,
  promptStep,
  randomPickStep,
  randomThreadStep,
} from './fixtures'
import type { FlowStep } from './useFlowRun'

const path = `/api/campaigns/${lostMineCampaign.id}`
const mira = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001'
const brann = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7002'
const saltmarsh = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3fa001'

/** The Play screen with the FlowRun waiting on `step`; `sent` collects the command bodies. */
function renderPick(step: FlowStep, commands: Record<string, FakeHandler> = {}) {
  const sent: Record<string, unknown> = {}
  const reads = { campaign: 0 }
  const handlers: Record<string, FakeHandler> = {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${path}`]: () => {
      reads.campaign += 1
      return Response.json(guidedWithLists(atStep(step)))
    },
    [`GET ${path}/journal`]: () => Response.json([]),
  }
  for (const [name, handler] of Object.entries(commands)) {
    handlers[`POST ${path}/${name}`] = async (request) => {
      const text = await request.clone().text()
      sent[name] = text === '' ? undefined : JSON.parse(text)
      return handler(request)
    }
  }
  return { ...renderAppAt(`/play/campaigns/${lostMineCampaign.id}`, handlers), sent, reads }
}

const answering =
  (next: Campaign): FakeHandler =>
  () =>
    Response.json(next)

const afterPick = guidedWithLists(atStep(promptStep))

async function card(title: string) {
  return within(await screen.findByRole('region', { name: `Current step: ${title}` }))
}

describe('a pick step in random mode', () => {
  it('names what is picked and the tags, and completes with a Pick button sending no ids', async () => {
    const user = userEvent.setup()
    const { sent } = renderPick(randomThreadStep, { 'flow-run/answer': answering(afterPick) })

    const step = await card('What comes up?')
    expect(step.getByText('Pick 1 Thread tagged Ally')).toBeInTheDocument()
    await user.click(step.getByRole('button', { name: 'Pick' }))

    await waitFor(() => {
      expect(sent['flow-run/answer']).toEqual({ stepKey: 'draw-thread' })
    })
    expect(await screen.findByRole('region', { name: 'Current step: Who walks in?' })).toBeVisible()
  })

  it('says any NPC when the step names no tags', async () => {
    renderPick(randomPickStep)

    const step = await card('Who is it?')
    expect(step.getByText('Pick 1 NPC')).toBeInTheDocument()
  })

  it('shows No match and Continue, sending no ids, when fewer candidates than the minimum remain', async () => {
    const user = userEvent.setup()
    const { sent } = renderPick(noMatchRandomStep, { 'flow-run/answer': answering(afterPick) })

    const step = await card('Who is drawn?')
    expect(step.getByText('No match')).toBeInTheDocument()
    expect(step.queryByRole('button', { name: 'Pick' })).not.toBeInTheDocument()
    await user.click(step.getByRole('button', { name: 'Continue' }))

    await waitFor(() => {
      expect(sent['flow-run/answer']).toEqual({ stepKey: 'nobody-drawn' })
    })
  })
})

describe('a pick step in choose mode', () => {
  it('lists the candidates with checkboxes and sends the ids once exactly the count is chosen', async () => {
    const user = userEvent.setup()
    const { sent } = renderPick(chooseOneStep, { 'flow-run/answer': answering(afterPick) })

    const step = await card('Who shows up?')
    expect(step.getByText('Choose 1 NPC tagged Leader')).toBeInTheDocument()
    const submit = step.getByRole('button', { name: 'Choose' })
    expect(submit).toBeDisabled()
    await user.click(step.getByRole('checkbox', { name: 'Brann' }))
    expect(submit).toBeEnabled()
    await user.click(step.getByRole('checkbox', { name: 'Mira Vane' }))
    expect(submit).toBeDisabled()
    await user.click(step.getByRole('checkbox', { name: 'Brann' }))
    await user.click(submit)

    await waitFor(() => {
      expect(sent['flow-run/answer']).toEqual({ stepKey: 'who', pickedIds: [mira] })
    })
  })

  it('accepts any number between the minimum and the maximum, in list order', async () => {
    const user = userEvent.setup()
    const { sent } = renderPick(chooseUpToTwoStep, { 'flow-run/answer': answering(afterPick) })

    const step = await card('Who comes along?')
    expect(step.getByText('Choose 1 to 2 NPCs tagged Leader')).toBeInTheDocument()
    await user.click(step.getByRole('checkbox', { name: 'Brann' }))
    await user.click(step.getByRole('checkbox', { name: 'Mira Vane' }))
    expect(step.getByRole('button', { name: 'Choose' })).toBeEnabled()
    await user.click(step.getByRole('button', { name: 'Choose' }))

    await waitFor(() => {
      expect(sent['flow-run/answer']).toEqual({ stepKey: 'who-two', pickedIds: [mira, brann] })
    })
  })

  it('keeps Choose disabled below the minimum', async () => {
    const user = userEvent.setup()
    renderPick(chooseTwoStep)

    const step = await card('Who must come?')
    await user.click(step.getByRole('checkbox', { name: 'Brann' }))
    expect(step.getByRole('button', { name: 'Choose' })).toBeDisabled()
    await user.click(step.getByRole('checkbox', { name: 'Mira Vane' }))
    expect(step.getByRole('button', { name: 'Choose' })).toBeEnabled()
  })

  it('shows No match when no candidate remains and continues with an empty list', async () => {
    const user = userEvent.setup()
    const { sent } = renderPick(noMatchChooseStep, { 'flow-run/answer': answering(afterPick) })

    const step = await card('Who is left?')
    expect(step.getByText('No match')).toBeInTheDocument()
    expect(step.queryByRole('checkbox')).not.toBeInTheDocument()
    await user.click(step.getByRole('button', { name: 'Continue' }))

    await waitFor(() => {
      expect(sent['flow-run/answer']).toEqual({ stepKey: 'nobody', pickedIds: [] })
    })
  })

  it('shows No match when fewer candidates than the minimum remain', async () => {
    const lone = {
      ...chooseTwoStep,
      pick: { ...chooseTwoStep.pick, candidates: chooseTwoStep.pick?.candidates.slice(0, 1) ?? [] },
    } as FlowStep
    renderPick(lone)

    const step = await card('Who must come?')
    expect(step.getByText('No match')).toBeInTheDocument()
    expect(step.queryByRole('checkbox')).not.toBeInTheDocument()
  })
})

describe('a pick step in general', () => {
  it('can be skipped like any suggested step', async () => {
    const user = userEvent.setup()
    const { sent } = renderPick(chooseOneStep, { 'flow-run/skip': answering(afterPick) })

    await user.click((await card('Who shows up?')).getByRole('button', { name: 'Skip' }))

    await waitFor(() => {
      expect(sent['flow-run/skip']).toEqual({ stepKey: 'who' })
    })
  })

  it('shows a refusal on the card and keeps the choice', async () => {
    const user = userEvent.setup()
    renderPick(chooseOneStep, {
      'flow-run/answer': () =>
        Response.json({ error: 'The id "x" is not a candidate.' }, { status: 422 }),
    })

    const step = await card('Who shows up?')
    await user.click(step.getByRole('checkbox', { name: 'Brann' }))
    await user.click(step.getByRole('button', { name: 'Choose' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('The id "x" is not a candidate.')
    expect(step.getByRole('checkbox', { name: 'Brann' })).toBeChecked()
  })

  it('shows a conflict and refetches the campaign', async () => {
    const user = userEvent.setup()
    const { reads } = renderPick(randomPickStep, {
      'flow-run/answer': () =>
        Response.json({ error: 'The FlowRun is at another step.' }, { status: 409 }),
    })

    await user.click((await card('Who is it?')).getByRole('button', { name: 'Pick' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('The FlowRun is at another step.')
    await waitFor(() => {
      expect(reads.campaign).toBe(2)
    })
  })

  it('fails closed on a pick step that arrives without its details', async () => {
    renderPick({ ...chooseOneStep, pick: null })

    const step = await card('Who shows up?')
    expect(step.getByRole('alert')).toHaveTextContent('This pick step has no details to show.')
    expect(step.queryByRole('button', { name: 'Choose' })).not.toBeInTheDocument()
  })
})

describe('a pick step over Characters', () => {
  it('lists the Characters as candidates and sends the one chosen, as for NPCs', async () => {
    const user = userEvent.setup()
    const { sent } = renderPick(chooseCharacterStep, { 'flow-run/answer': answering(afterPick) })

    const step = await card('Who takes the job?')
    expect(step.getByText('Choose 1 Character')).toBeInTheDocument()
    await user.click(step.getByRole('checkbox', { name: 'Vi' }))
    await user.click(step.getByRole('button', { name: 'Choose' }))

    await waitFor(() => {
      expect(sent['flow-run/answer']).toEqual({
        stepKey: 'who-plays',
        pickedIds: [viCharacter.id],
      })
    })
  })

  it('says No match when there is no Character to pick', async () => {
    renderPick(noMatchCharacterStep)

    expect((await card('Who is left to play?')).getByText('No match')).toBeInTheDocument()
  })
})

describe('a pick step over Places', () => {
  it('lists the Places as candidates and sends the one chosen, as for NPCs', async () => {
    const user = userEvent.setup()
    const { sent } = renderPick(choosePlaceStep, { 'flow-run/answer': answering(afterPick) })

    const step = await card('Where do you go?')
    expect(step.getByText('Choose 1 Place')).toBeInTheDocument()
    await user.click(step.getByRole('checkbox', { name: 'Saltmarsh' }))
    await user.click(step.getByRole('button', { name: 'Choose' }))

    await waitFor(() => {
      expect(sent['flow-run/answer']).toEqual({ stepKey: 'where', pickedIds: [saltmarsh] })
    })
  })
})
