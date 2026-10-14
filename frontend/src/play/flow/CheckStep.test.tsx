import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { lostMineCampaign } from '../campaigns/fixtures'
import type { Campaign } from '../campaigns/useCampaigns'
import { viCharacter } from '../characters/fixtures'
import { atStep, checkStep, guidedWithChecks, promptStep } from './fixtures'
import type { FlowStep } from './useFlowRun'

const path = `/api/campaigns/${lostMineCampaign.id}`
const afterStep = guidedWithChecks(atStep(promptStep))
const ada = { id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9003', name: 'Ada' }
const vi = { id: viCharacter.id, name: viCharacter.name }

/** The Play screen with the FlowRun waiting on `step`; `sent` collects the answers. */
function renderStep(step: FlowStep, campaign: Campaign = guidedWithChecks(atStep(step))) {
  const sent: unknown[] = []
  const answer: FakeHandler = async (request) => {
    sent.push(await request.clone().json())
    return Response.json(afterStep)
  }
  renderAppAt(`/play/campaigns/${lostMineCampaign.id}`, {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${path}`]: () => Response.json(campaign),
    [`GET ${path}/journal`]: () => Response.json([]),
    [`POST ${path}/flow-run/answer`]: answer,
  })
  return sent
}

async function card(title = checkStep.title) {
  return within(await screen.findByRole('region', { name: `Current step: ${title}` }))
}

describe('a check step', () => {
  it('preselects and shows the only Character, with the check, hint and default difficulty', async () => {
    const sent = renderStep(checkStep)
    const user = userEvent.setup()

    const step = await card()
    expect(step.getByLabelText('Character')).toHaveValue(vi.id)
    expect(step.getByText('Skill check')).toBeInTheDocument()
    expect(step.getByText('A stat and 1d10 against a Difficulty Value.')).toBeInTheDocument()
    expect(step.getByLabelText('Difficulty Value')).toHaveValue(13)
    await user.click(step.getByRole('button', { name: 'Roll' }))

    await waitFor(() => {
      expect(sent).toEqual([{ stepKey: 'sneak', characterId: vi.id, difficulty: 13 }])
    })
    expect(await screen.findByRole('region', { name: 'Current step: Who walks in?' })).toBeVisible()
  })

  it('sends the difficulty and the situational modifier the player set', async () => {
    const sent = renderStep(checkStep)
    const user = userEvent.setup()

    const step = await card()
    await user.clear(step.getByLabelText('Difficulty Value'))
    await user.type(step.getByLabelText('Difficulty Value'), '21')
    await user.clear(step.getByLabelText('Situational modifier'))
    await user.type(step.getByLabelText('Situational modifier'), '3')
    await user.click(step.getByRole('button', { name: 'Roll' }))

    await waitFor(() => {
      expect(sent).toEqual([
        { stepKey: 'sneak', characterId: vi.id, difficulty: 21, situational: 3 },
      ])
    })
  })

  it('shows a difficulty the step fixes as read-only text and does not send it', async () => {
    const sent = renderStep({ ...checkStep, difficulty: 15 })
    const user = userEvent.setup()

    const step = await card()
    expect(step.queryByLabelText('Difficulty Value')).not.toBeInTheDocument()
    expect(step.getByText('Difficulty Value:')).toHaveTextContent('Difficulty Value: 15')
    await user.click(step.getByRole('button', { name: 'Roll' }))

    await waitFor(() => {
      expect(sent).toEqual([{ stepKey: 'sneak', characterId: vi.id }])
    })
  })

  it('asks no difficulty for a check that declares none', async () => {
    const sent = renderStep({ ...checkStep, check: 'luck' })
    const user = userEvent.setup()

    const step = await card()
    expect(step.queryByLabelText('Difficulty Value')).not.toBeInTheDocument()
    await user.click(step.getByRole('button', { name: 'Roll' }))

    await waitFor(() => {
      expect(sent).toEqual([{ stepKey: 'sneak', characterId: vi.id }])
    })
  })

  it('makes the player choose among several Characters before rolling', async () => {
    const sent = renderStep({ ...checkStep, characters: [vi, ada] })
    const user = userEvent.setup()

    const step = await card()
    expect(step.getByLabelText('Character')).toHaveValue('')
    expect(step.getByRole('button', { name: 'Roll' })).toBeDisabled()
    await user.selectOptions(step.getByLabelText('Character'), 'Ada')
    await user.click(step.getByRole('button', { name: 'Roll' }))

    await waitFor(() => {
      expect(sent).toEqual([{ stepKey: 'sneak', characterId: ada.id, difficulty: 13 }])
    })
  })

  it('says so, and offers no roll, when no Character can roll the check', async () => {
    renderStep({ ...checkStep, characters: [] })

    const step = await card()
    expect(step.getByText(/No Character can roll this check/)).toBeInTheDocument()
    expect(step.queryByRole('button', { name: 'Roll' })).not.toBeInTheDocument()
  })

  it('fails closed with a message when the release does not define the check', async () => {
    renderStep({ ...checkStep, check: 'ghost' })

    const step = await card()
    expect(step.getByRole('alert')).toHaveTextContent('which the release does not define')
    expect(step.queryByRole('button', { name: 'Roll' })).not.toBeInTheDocument()
  })

  it('shows the refusal of the server and keeps the step', async () => {
    const campaign = guidedWithChecks(atStep(checkStep))
    renderAppAt(`/play/campaigns/${lostMineCampaign.id}`, {
      'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
      [`GET ${path}`]: () => Response.json(campaign),
      [`GET ${path}/journal`]: () => Response.json([]),
      [`POST ${path}/flow-run/answer`]: () =>
        Response.json(
          { error: 'Difficulty 40 is outside 9 to 29 of check "skill".' },
          { status: 422 },
        ),
    })
    const user = userEvent.setup()

    const step = await card()
    await user.click(step.getByRole('button', { name: 'Roll' }))

    expect(await screen.findByText(/Difficulty 40 is outside 9 to 29/)).toBeVisible()
    expect(await card()).toBeDefined()
  })
})
