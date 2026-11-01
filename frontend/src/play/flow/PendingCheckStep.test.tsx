import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { edgeCheck, gritCheck, momentumField, persuadeCheck } from '../campaigns/fixtures'
import type { Campaign } from '../campaigns/useCampaigns'
import { conditionsField, nightJobCampaign, sheet, viCharacter } from '../characters/fixtures'
import {
  bestialFailureEntry,
  burnableRollEntry,
  gritEntry,
  hungerOnlyFailedEntry,
} from '../journal/fixtures'
import { atStep, guidedWithCharacters, promptStep, checkStep, pendingGritStep } from './fixtures'
import type { FlowStep } from './useFlowRun'

const path = `/api/campaigns/${nightJobCampaign.id}`

/** The Night Job guided on `step`, with the pushable "Grit" and Vi's conditions field "Wounds" (hurt marked). */
function guidedOnGrit(step: FlowStep, check: Campaign['checks'][number] = gritCheck): Campaign {
  return {
    ...guidedWithCharacters(atStep(step)),
    checks: [check],
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
    check = gritCheck,
    hold,
    campaign: read,
  }: {
    refuse?: Response
    journal?: FakeHandler
    unpushable?: boolean
    check?: Campaign['checks'][number]
    hold?: Promise<void>
    /** Replaces the campaign the screen reads, for a sheet that changes between reads. */
    campaign?: () => Campaign
  } = {},
) {
  const sent: { keep: unknown[]; push: unknown[]; reads: number } = {
    keep: [],
    push: [],
    reads: 0,
  }
  const afterStep = guidedOnGrit(promptStep)
  const answer =
    (into: unknown[]): FakeHandler =>
    async (request) => {
      into.push(await request.clone().json())
      await hold
      return refuse?.clone() ?? Response.json(afterStep)
    }
  const campaign = guidedOnGrit(step, check)
  renderAppAt(`/play/campaigns/${nightJobCampaign.id}`, {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${path}`]: () => {
      sent.reads += 1
      if (read !== undefined) {
        return Response.json(read())
      }
      return Response.json(
        unpushable ? { ...campaign, checks: [{ ...check, push: null }] } : campaign,
      )
    },
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

describe('a Hunger roll that pends', () => {
  const pendingId = pendingGritStep.pending?.entryId ?? ''

  it('shows the special result and the cap of the push', async () => {
    renderGrit(pendingGritStep, {
      check: persuadeCheck,
      journal: () => Response.json([{ ...bestialFailureEntry, id: pendingId }]),
    })

    const step = await card()
    expect(await step.findByText('Re-roll up to 3 failed dice.')).toBeInTheDocument()
    expect(step.getByText('Special result:').parentElement).toHaveTextContent('Bestial failure')
    expect(step.getByRole('button', { name: 'Push' })).toBeEnabled()
  })

  it('offers only Keep when the dice that failed are Hunger dice', async () => {
    renderGrit(pendingGritStep, {
      check: persuadeCheck,
      journal: () => Response.json([{ ...hungerOnlyFailedEntry, id: pendingId }]),
    })

    const step = await card()
    expect(await step.findByRole('button', { name: 'Keep' })).toBeEnabled()
    expect(step.queryByRole('button', { name: 'Push' })).not.toBeInTheDocument()
  })
})

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

/** Vi's guided Night Job on the pending burnable "Edge" roll; her sheet holds `momentum` (none when null). */
function guidedOnEdge(momentum: number | null, characterId = viCharacter.id): Campaign {
  const values: Record<string, unknown> = { ...viCharacter.values }
  if (momentum !== null) {
    values.momentum = momentum
  }
  return {
    ...guidedWithCharacters(atStep(pendingEdgeStep)),
    checks: [edgeCheck],
    pinnedRelease: { ...nightJobCampaign.pinnedRelease, sheet: [...sheet, momentumField] },
    characters: [
      {
        ...viCharacter,
        id: characterId,
        values: values as Campaign['characters'][number]['values'],
      },
    ],
  }
}

const pendingEdgeStep: FlowStep = {
  ...pendingGritStep,
  pending: { characterId: viCharacter.id, entryId: burnableRollEntry.id },
}

describe('a challenge roll that pends (ADR 0049)', () => {
  const edgeJournal = () => Response.json([burnableRollEntry])

  it('offers Burn and Keep with the momentum now and the dice it would beat', async () => {
    renderGrit(pendingEdgeStep, { journal: edgeJournal, campaign: () => guidedOnEdge(8) })

    const step = await card()
    expect(await step.findByRole('button', { name: 'Burn' })).toBeEnabled()
    expect(step.getByRole('button', { name: 'Keep' })).toBeEnabled()
    expect(step.getByText(/Momentum now:/)).toHaveTextContent('Momentum now: 8 · score 8')
    expect(
      step.getByText('It would beat 2 of 2 challenge dice, against 1 with the roll.'),
    ).toBeInTheDocument()
    expect(step.queryByRole('button', { name: 'Push' })).not.toBeInTheDocument()
  })

  it('burns through the push command of the step', async () => {
    const sent = renderGrit(pendingEdgeStep, {
      journal: edgeJournal,
      campaign: () => guidedOnEdge(8),
    })
    const user = userEvent.setup()

    await user.click(await (await card()).findByRole('button', { name: 'Burn' }))

    await waitFor(() => {
      expect(sent.push).toEqual([{ stepKey: 'sneak', picks: [] }])
    })
  })

  it('keeps only Keep, and says why, when the momentum no longer beats more dice', async () => {
    const sent = renderGrit(pendingEdgeStep, {
      journal: edgeJournal,
      campaign: () => guidedOnEdge(5),
    })
    const user = userEvent.setup()

    const step = await card()
    expect(await step.findByText(/Momentum now:/)).toHaveTextContent('Momentum now: 5')
    expect(
      step.getByText(
        'It would beat only 1 of 2 challenge dice, against 1 with the roll: burning it would not help.',
      ),
    ).toBeInTheDocument()
    expect(step.queryByRole('button', { name: 'Burn' })).not.toBeInTheDocument()
    await user.click(step.getByRole('button', { name: 'Keep' }))

    await waitFor(() => {
      expect(sent.keep).toEqual([{ stepKey: 'sneak' }])
    })
  })

  it('keeps Keep when the Character who rolled is gone or the momentum cannot be read', async () => {
    renderGrit(pendingEdgeStep, {
      journal: edgeJournal,
      campaign: () => guidedOnEdge(8, 'someone-else'),
    })

    const step = await card()
    expect(await step.findByText(/cannot be read from the sheet/)).toBeInTheDocument()
    expect(step.queryByRole('button', { name: 'Burn' })).not.toBeInTheDocument()
    expect(step.getByRole('button', { name: 'Keep' })).toBeEnabled()
  })

  it('shows the refusal of a stale sheet, reads the campaign again and refreshes the offer', async () => {
    let momentum = 8
    const message = 'The momentum no longer beats more challenge dice than the roll.'
    const sent = renderGrit(pendingEdgeStep, {
      journal: edgeJournal,
      campaign: () => guidedOnEdge(momentum),
      refuse: Response.json({ error: message }, { status: 409 }),
    })
    const user = userEvent.setup()

    const step = await card()
    const burn = await step.findByRole('button', { name: 'Burn' })
    // The sheet the server holds changed between the offer and the burn.
    momentum = 5
    const before = sent.reads
    await user.click(burn)

    expect(await screen.findByRole('alert')).toHaveTextContent(message)
    await waitFor(() => {
      expect(sent.reads).toBeGreaterThan(before)
    })
    await waitFor(() => {
      expect(step.queryByRole('button', { name: 'Burn' })).not.toBeInTheDocument()
    })
    expect(step.getByText(/Momentum now:/)).toHaveTextContent('Momentum now: 5')
    expect(step.getByRole('button', { name: 'Keep' })).toBeEnabled()
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
