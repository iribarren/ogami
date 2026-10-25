import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { gritCheck } from '../campaigns/fixtures'
import type { Campaign } from '../campaigns/useCampaigns'
import { conditionsField, nightJobCampaign, sheet, viCharacter } from '../characters/fixtures'
import { gritEntry } from '../journal/fixtures'
import { atStep, guidedWithCharacters, promptStep, checkStep, pendingGritStep } from './fixtures'
import type { FlowStep } from './useFlowRun'

const path = `/api/campaigns/${nightJobCampaign.id}`

/** The Night Job guided on `step`, with the pushable "Grit" and Vi's conditions field "Wounds" (hurt marked). */
function guidedOnGrit(step: FlowStep): Campaign {
  return {
    ...guidedWithCharacters(atStep(step)),
    checks: [gritCheck],
    pinnedRelease: { ...nightJobCampaign.pinnedRelease, sheet: [...sheet, conditionsField] },
    characters: [{ ...viCharacter, values: { ...viCharacter.values, wounds: ['hurt'] } }],
  }
}

/** The Play screen on a Grit step; `sent` collects the bodies the Keep and Push endpoints get. */
function renderGrit(step: FlowStep, refuse?: Response) {
  const sent: { keep: unknown[]; push: unknown[] } = { keep: [], push: [] }
  const afterStep = guidedOnGrit(promptStep)
  const answer =
    (into: unknown[]): FakeHandler =>
    async (request) => {
      into.push(await request.clone().json())
      return refuse?.clone() ?? Response.json(afterStep)
    }
  renderAppAt(`/play/campaigns/${nightJobCampaign.id}`, {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${path}`]: () => Response.json(guidedOnGrit(step)),
    [`GET ${path}/journal`]: () => Response.json([gritEntry]),
    [`POST ${path}/flow-run/keep`]: answer(sent.keep),
    [`POST ${path}/flow-run/push`]: answer(sent.push),
  })
  return sent
}

async function card() {
  return within(
    await screen.findByRole('region', { name: `Current step: ${pendingGritStep.title}` }),
  )
}

describe('a check step whose roll pends', () => {
  it('shows the pending roll and the push offer, with no roll form and no Skip', async () => {
    renderGrit(pendingGritStep)

    const step = await card()
    expect(await step.findByRole('button', { name: 'Push' })).toBeDisabled()
    expect(step.getByRole('button', { name: 'Keep' })).toBeEnabled()
    expect(step.getByText('Take a Condition')).toBeInTheDocument()
    expect(step.getByLabelText('Pick: Wounds')).toBeInTheDocument()
    expect(step.getByText(/Grit/)).toBeInTheDocument()
    expect(step.queryByRole('button', { name: 'Roll' })).not.toBeInTheDocument()
    expect(step.queryByRole('button', { name: 'Skip' })).not.toBeInTheDocument()
  })

  it('keeps the roll', async () => {
    const sent = renderGrit(pendingGritStep)
    const user = userEvent.setup()

    await user.click(await (await card()).findByRole('button', { name: 'Keep' }))

    await waitFor(() => {
      expect(sent.keep).toEqual([{ stepKey: 'sneak' }])
    })
    expect(await screen.findByRole('region', { name: 'Current step: Who walks in?' })).toBeVisible()
  })

  it('pushes with the picks the player chose, once every pick is made', async () => {
    const sent = renderGrit(pendingGritStep)
    const user = userEvent.setup()

    const step = await card()
    expect(await step.findByRole('button', { name: 'Push' })).toBeDisabled()
    await user.selectOptions(step.getByLabelText('Pick: Wounds'), 'shaken')
    await user.click(step.getByRole('button', { name: 'Push' }))

    await waitFor(() => {
      expect(sent.push).toEqual([{ stepKey: 'sneak', picks: ['shaken'] }])
    })
  })

  it.each([
    [409, 'Keep or push the pending roll first.'],
    [422, 'The pick is not an option of Wounds.'],
  ])('shows the refusal of %i on the card', async (status, message) => {
    renderGrit(pendingGritStep, Response.json({ error: message }, { status }))
    const user = userEvent.setup()

    await user.click(await (await card()).findByRole('button', { name: 'Keep' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(message)
    expect(await card()).toBeTruthy()
  })

  it('says it is loading while the roll is not in the journal yet', async () => {
    renderGrit({
      ...pendingGritStep,
      pending: { characterId: viCharacter.id, entryId: 'gone' },
    })

    expect(await (await card()).findByText('Loading the pending roll…')).toBeInTheDocument()
  })
})

describe('a check step that does not pend', () => {
  it('keeps its roll form and Skip', async () => {
    renderGrit({ ...checkStep, check: gritCheck.key })

    const step = within(
      await screen.findByRole('region', { name: `Current step: ${checkStep.title}` }),
    )
    expect(await step.findByRole('button', { name: 'Roll' })).toBeInTheDocument()
    expect(step.getByRole('button', { name: 'Skip' })).toBeInTheDocument()
    expect(step.queryByRole('button', { name: 'Keep' })).not.toBeInTheDocument()
  })
})
