import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { lostMineCampaign } from '../campaigns/fixtures'
import type { Campaign } from '../campaigns/useCampaigns'
import { viCharacter } from '../characters/fixtures'
import {
  atStep,
  choiceStep,
  guidedWithCharacters,
  guidedWithShop,
  promptStep,
  sheetChoiceStep,
  sheetPromptStep,
} from './fixtures'
import type { FlowStep } from './useFlowRun'

const path = `/api/campaigns/${lostMineCampaign.id}`
const boCharacter = { ...viCharacter, id: 'char-bo', name: 'Bo' }

/** The Play screen with the FlowRun waiting on `step`; `sent` collects the bodies of the answers. */
function renderStep(step: FlowStep, characters: Campaign['characters'] = [viCharacter]) {
  const sent: unknown[] = []
  const campaign = { ...guidedWithCharacters(atStep(step)), characters }
  const answer: FakeHandler = async (request) => {
    sent.push(await request.clone().json())
    return Response.json(guidedWithCharacters(atStep(promptStep)))
  }
  renderAppAt(`/play/campaigns/${lostMineCampaign.id}`, {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${path}`]: () => Response.json(campaign),
    [`GET ${path}/journal`]: () => Response.json([]),
    [`POST ${path}/flow-run/answer`]: answer,
  })
  return sent
}

/** Like `renderStep`, with the campaign's Characters changeable after mount. */
function renderChangingStep(step: FlowStep) {
  const sent: unknown[] = []
  let characters: Campaign['characters'] = []
  const { queryClient } = renderAppAt(`/play/campaigns/${lostMineCampaign.id}`, {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${path}`]: () => Response.json({ ...guidedWithCharacters(atStep(step)), characters }),
    [`GET ${path}/journal`]: () => Response.json([]),
    [`POST ${path}/flow-run/answer`]: async (request) => {
      sent.push(await request.clone().json())
      return Response.json(guidedWithCharacters(atStep(promptStep)))
    },
  })
  return {
    sent,
    gainCharacters: async (next: Campaign['characters']) => {
      characters = next
      await queryClient.invalidateQueries()
    },
  }
}

async function card(step: FlowStep) {
  return within(await screen.findByRole('region', { name: `Current step: ${step.title}` }))
}

describe('a choice option that changes a sheet', () => {
  it('asks for the Character only once that option is picked, preselected when there is one', async () => {
    const user = userEvent.setup()
    const sent = renderStep(sheetChoiceStep)

    const step = await card(sheetChoiceStep)
    expect(step.queryByLabelText('Character')).not.toBeInTheDocument()
    await user.click(step.getByRole('button', { name: 'Learned' }))

    expect(step.getByLabelText('Character')).toHaveValue(viCharacter.id)
    await user.click(step.getByRole('button', { name: 'Confirm' }))

    await waitFor(() => {
      expect(sent).toEqual([
        { stepKey: 'learned', optionKey: 'learned', characterId: viCharacter.id },
      ])
    })
  })

  it('answers an option that changes nothing at once, without a Character', async () => {
    const user = userEvent.setup()
    const sent = renderStep(sheetChoiceStep)

    const step = await card(sheetChoiceStep)
    await user.click(step.getByRole('button', { name: 'Learned' }))
    await user.click(step.getByRole('button', { name: 'Nothing' }))

    await waitFor(() => {
      expect(sent).toEqual([{ stepKey: 'learned', optionKey: 'nothing' }])
    })
    expect(step.queryByLabelText('Character')).not.toBeInTheDocument()
  })

  it('waits for the player to choose among several Characters', async () => {
    const user = userEvent.setup()
    const sent = renderStep(sheetChoiceStep, [viCharacter, boCharacter])

    const step = await card(sheetChoiceStep)
    await user.click(step.getByRole('button', { name: 'Learned' }))
    expect(step.getByLabelText('Character')).toHaveValue('')
    expect(step.getByRole('button', { name: 'Confirm' })).toBeDisabled()
    await user.selectOptions(step.getByLabelText('Character'), 'Bo')
    await user.click(step.getByRole('button', { name: 'Confirm' }))

    await waitFor(() => {
      expect(sent).toEqual([
        { stepKey: 'learned', optionKey: 'learned', characterId: boCharacter.id },
      ])
    })
  })

  it('says so when the campaign has no Character to name', async () => {
    const user = userEvent.setup()
    renderStep(sheetChoiceStep, [])

    const step = await card(sheetChoiceStep)
    await user.click(step.getByRole('button', { name: 'Learned' }))

    expect(step.getByText(/campaign has no Character yet/)).toBeVisible()
    expect(step.getByRole('button', { name: 'Confirm' })).toBeDisabled()
  })

  it('never asks for a Character on a choice without such options', async () => {
    const user = userEvent.setup()
    const sent = renderStep(choiceStep)

    const step = await card(choiceStep)
    await user.click(step.getByRole('button', { name: 'Go left' }))

    await waitFor(() => {
      expect(sent).toEqual([{ stepKey: 'fork', optionKey: 'left' }])
    })
    expect(step.queryByLabelText('Character')).not.toBeInTheDocument()
  })
})

describe('a prompt that changes a sheet', () => {
  it('asks for the Character, preselected when there is one, and sends it with the answer', async () => {
    const user = userEvent.setup()
    const sent = renderStep(sheetPromptStep)

    const step = await card(sheetPromptStep)
    expect(step.getByLabelText('Character')).toHaveValue(viCharacter.id)
    await user.type(step.getByLabelText('Your answer'), 'A scar')
    await user.click(step.getByRole('button', { name: 'Record' }))

    await waitFor(() => {
      expect(sent).toEqual([{ stepKey: 'cost', text: 'A scar', characterId: viCharacter.id }])
    })
  })

  it('answers with the only Character when the campaign gains it after the step shows', async () => {
    const user = userEvent.setup()
    const { sent, gainCharacters } = renderChangingStep(sheetPromptStep)

    const step = await card(sheetPromptStep)
    await user.type(step.getByLabelText('Your answer'), 'A scar')
    expect(step.getByRole('button', { name: 'Record' })).toBeDisabled()

    await gainCharacters([viCharacter])

    await waitFor(() => {
      expect(step.getByLabelText('Character')).toHaveValue(viCharacter.id)
    })
    await user.click(step.getByRole('button', { name: 'Record' }))
    await waitFor(() => {
      expect(sent).toEqual([{ stepKey: 'cost', text: 'A scar', characterId: viCharacter.id }])
    })
  })

  it('cannot be recorded before a Character is chosen among several', async () => {
    const user = userEvent.setup()
    renderStep(sheetPromptStep, [viCharacter, boCharacter])

    const step = await card(sheetPromptStep)
    await user.type(step.getByLabelText('Your answer'), 'A scar')
    expect(step.getByRole('button', { name: 'Record' })).toBeDisabled()
    await user.selectOptions(step.getByLabelText('Character'), 'Bo')

    expect(step.getByRole('button', { name: 'Record' })).toBeEnabled()
  })

  it('sends no Character and shows no select for a prompt that changes nothing', async () => {
    const user = userEvent.setup()
    const sent = renderStep(promptStep)

    const step = await card(promptStep)
    expect(step.queryByLabelText('Character')).not.toBeInTheDocument()
    await user.type(step.getByLabelText('Your answer'), 'Ada')
    await user.click(step.getByRole('button', { name: 'Record' }))

    await waitFor(() => {
      expect(sent).toEqual([{ stepKey: 'intro', text: 'Ada' }])
    })
  })

  it('says in money terms when an option, a prompt or a character step spends too much', async () => {
    const user = userEvent.setup()
    renderAppAt(`/play/campaigns/${lostMineCampaign.id}`, {
      'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
      [`GET ${path}`]: () => Response.json(guidedWithShop(atStep(sheetChoiceStep))),
      [`GET ${path}/journal`]: () => Response.json([]),
      [`POST ${path}/flow-run/answer`]: () =>
        Response.json(
          { error: 'Not enough "eddies": raw', code: 'insufficient_funds' },
          { status: 422 },
        ),
    })

    const step = await card(sheetChoiceStep)
    await user.click(step.getByRole('button', { name: 'Learned' }))
    await user.click(step.getByRole('button', { name: 'Confirm' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Not enough Eddies for that.')
  })
})
