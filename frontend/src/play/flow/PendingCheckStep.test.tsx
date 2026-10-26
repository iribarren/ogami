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

/**
 * The Play screen on a Grit step; `sent` collects the bodies the Keep and Push endpoints get.
 * `refuse` answers both endpoints, `journal` answers the journal (default: Vi's roll), `unpushable`
 * pins a Grit that declares no push, and `hold` keeps the commands waiting until it resolves.
 */
function renderGrit(
  step: FlowStep,
  {
    refuse,
    journal = () => Response.json([gritEntry]),
    unpushable = false,
    hold,
  }: {
    refuse?: Response
    journal?: FakeHandler
    unpushable?: boolean
    hold?: Promise<void>
  } = {},
) {
  const sent: { keep: unknown[]; push: unknown[] } = { keep: [], push: [] }
  const afterStep = guidedOnGrit(promptStep)
  const answer =
    (into: unknown[]): FakeHandler =>
    async (request) => {
      into.push(await request.clone().json())
      await hold
      return refuse?.clone() ?? Response.json(afterStep)
    }
  const campaign = guidedOnGrit(step)
  renderAppAt(`/play/campaigns/${nightJobCampaign.id}`, {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${path}`]: () =>
      Response.json(
        unpushable ? { ...campaign, checks: [{ ...gritCheck, push: null }] } : campaign,
      ),
    [`GET ${path}/journal`]: journal,
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
    renderGrit(pendingGritStep, { refuse: Response.json({ error: message }, { status }) })
    const user = userEvent.setup()

    await user.click(await (await card()).findByRole('button', { name: 'Keep' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(message)
    expect(await card()).toBeTruthy()
  })

  it.each([
    [409, 'The roll was pushed already.'],
    [422, 'The pick "ghost" is not an unmarked option of Wounds.'],
  ])('shows the refusal of %i of a Push on the card', async (status, message) => {
    renderGrit(pendingGritStep, { refuse: Response.json({ error: message }, { status }) })
    const user = userEvent.setup()

    const step = await card()
    await user.selectOptions(await step.findByLabelText('Pick: Wounds'), 'shaken')
    await user.click(step.getByRole('button', { name: 'Push' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(message)
    expect(step.getByRole('button', { name: 'Keep' })).toBeEnabled()
  })

  it('disables Push and Keep while a command is under way', async () => {
    let release: () => void = () => undefined
    const hold = new Promise<void>((resolve) => {
      release = resolve
    })
    const sent = renderGrit(pendingGritStep, { hold })
    const user = userEvent.setup()

    const step = await card()
    await user.selectOptions(await step.findByLabelText('Pick: Wounds'), 'shaken')
    await user.click(step.getByRole('button', { name: 'Push' }))

    await waitFor(() => {
      expect(step.getByRole('button', { name: 'Push' })).toBeDisabled()
    })
    expect(step.getByRole('button', { name: 'Keep' })).toBeDisabled()
    expect(sent.push).toHaveLength(1)
    release()
    expect(await screen.findByRole('region', { name: 'Current step: Who walks in?' })).toBeVisible()
  })

  it('says it is loading while the journal is not there yet', async () => {
    renderGrit(pendingGritStep, { journal: () => new Promise<Response>(() => undefined) })

    expect(await (await card()).findByText('Loading the pending roll…')).toBeInTheDocument()
  })

  it('still offers Keep when the journal does not hold the pending roll', async () => {
    const sent = renderGrit({
      ...pendingGritStep,
      pending: { characterId: viCharacter.id, entryId: 'gone' },
    })
    const user = userEvent.setup()

    const step = await card()
    expect(await step.findByText('The pending roll is not in the journal.')).toBeInTheDocument()
    expect(step.queryByRole('button', { name: 'Push' })).not.toBeInTheDocument()
    await user.click(step.getByRole('button', { name: 'Keep' }))

    await waitFor(() => {
      expect(sent.keep).toEqual([{ stepKey: 'sneak' }])
    })
  })

  it('still offers Keep when the journal failed to load', async () => {
    renderGrit(pendingGritStep, {
      journal: () => Response.json({ error: 'The journal is down.' }, { status: 500 }),
    })

    const step = await card()
    expect(await step.findByRole('alert')).toBeInTheDocument()
    expect(step.getByRole('button', { name: 'Keep' })).toBeEnabled()
  })

  it('still offers Keep when the roll has no push to offer', async () => {
    const sent = renderGrit(pendingGritStep, { unpushable: true })
    const user = userEvent.setup()

    const step = await card()
    expect(await step.findByRole('button', { name: 'Keep' })).toBeEnabled()
    expect(step.queryByRole('button', { name: 'Push' })).not.toBeInTheDocument()
    await user.click(step.getByRole('button', { name: 'Keep' }))

    await waitFor(() => {
      expect(sent.keep).toEqual([{ stepKey: 'sneak' }])
    })
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
