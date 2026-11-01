import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import {
  edgeCheck,
  gritCheck,
  momentumField,
  persuadeCheck,
  skillCheck,
} from '../campaigns/fixtures'
import type { Campaign } from '../campaigns/useCampaigns'
import { conditionsField, nightJobCampaign, sheet, viCharacter } from '../characters/fixtures'
import {
  bestialFailureEntry,
  burnableRollEntry,
  burnedEntry,
  gritEntry,
  hungerOnlyFailedEntry,
  pushedGritEntry,
  recordedEntry,
  skillCheckEntry,
} from '../journal/fixtures'

const path = `/api/campaigns/${nightJobCampaign.id}`

/** The campaign of Vi with the pushable check "Grit" and a conditions field "Wounds" (hurt, shaken, tired). */
function pushable(marked: string[] = ['hurt']): Campaign {
  return {
    ...nightJobCampaign,
    checks: [gritCheck],
    pinnedRelease: { ...nightJobCampaign.pinnedRelease, sheet: [...sheet, conditionsField] },
    characters: [{ ...viCharacter, values: { ...viCharacter.values, wounds: marked } }],
  }
}

/**
 * Renders the Play screen; `rolled` answers the roll, `push` the push, and `sent` collects the
 * pushes' bodies. `reads` collects the campaign and journal reads, in order.
 */
function renderPush(
  campaign: Campaign,
  rolled: FakeHandler,
  push?: FakeHandler,
  reads: string[] = [],
) {
  const sent: unknown[] = []
  renderAppAt(`/play/campaigns/${campaign.id}`, {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${path}`]: () => {
      reads.push('campaign')
      return Response.json(campaign)
    },
    [`GET ${path}/journal`]: () => {
      reads.push('journal')
      return Response.json([])
    },
    [`POST ${path}/journal/checks`]: rolled,
    [`POST ${path}/journal/${gritEntry.id}/push`]: async (request: Request) => {
      sent.push(await request.clone().json())
      return push === undefined ? Response.json(pushedGritEntry) : push(request)
    },
  })
  return sent
}

const rolledGrit = () => Response.json(gritEntry, { status: 201 })

async function rollGrit(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole('button', { name: 'Roll a check for Vi' }))
  const dialog = within(await screen.findByRole('dialog', { name: 'Roll a check for Vi' }))
  await user.click(dialog.getByRole('button', { name: 'Roll' }))
  return dialog
}

/** Vi's campaign whose only check is the Hunger check "Persuade" (a push capped at 3 dice, or none). */
function hungry(check = persuadeCheck): Campaign {
  return { ...nightJobCampaign, checks: [check] }
}

async function rollPersuade(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole('button', { name: 'Roll a check for Vi' }))
  const dialog = within(await screen.findByRole('dialog', { name: 'Roll a check for Vi' }))
  await user.click(dialog.getByRole('button', { name: 'Roll' }))
  return dialog
}

describe('a Hunger roll in the "Roll a check" dialog', () => {
  it('shows the special result and the cap of the push, and counts the failed regular dice only', async () => {
    const user = userEvent.setup()
    renderPush(hungry(), () => Response.json(bestialFailureEntry, { status: 201 }))

    const dialog = await rollPersuade(user)

    expect(await dialog.findByText('Special result:')).toBeInTheDocument()
    expect(dialog.getByText('Special result:').parentElement).toHaveTextContent('Bestial failure')
    expect(dialog.getByText('Re-roll up to 3 failed dice.')).toBeInTheDocument()
    expect(dialog.getByText(/Hunger dice are never re-rolled/)).toBeInTheDocument()
    expect(dialog.getByRole('button', { name: 'Push' })).toBeEnabled()
  })

  it('closes at once when only Hunger dice failed: there is no push to offer', async () => {
    const user = userEvent.setup()
    renderPush(hungry(), () => Response.json(hungerOnlyFailedEntry, { status: 201 }))

    await rollPersuade(user)

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
  })

  it('stays open on a special result that cannot be pushed, to read it', async () => {
    const user = userEvent.setup()
    renderPush(hungry({ ...persuadeCheck, push: null }), () =>
      Response.json(bestialFailureEntry, { status: 201 }),
    )

    const dialog = await rollPersuade(user)

    expect(await dialog.findByText('Special result:')).toBeInTheDocument()
    expect(dialog.queryByRole('button', { name: 'Push' })).not.toBeInTheDocument()
    await user.click(dialog.getByRole('button', { name: 'Done' }))
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
  })
})

describe('pushing a pool check from the "Roll a check" dialog', () => {
  it('stays open on the result and offers the cost with a select of the options still unmarked', async () => {
    const user = userEvent.setup()
    renderPush(pushable(), rolledGrit)

    const dialog = await rollGrit(user)

    expect(await dialog.findByText('Take a Condition')).toBeInTheDocument()
    expect(dialog.getByText('Successes').nextElementSibling).toHaveTextContent('1 success')
    const pick = dialog.getByLabelText('Pick: Wounds')
    expect(
      within(pick)
        .getAllByRole('option')
        .map((option) => option.textContent),
    ).toEqual(['Choose', 'Shaken', 'Tired'])
    expect(dialog.getByRole('button', { name: 'Push' })).toBeDisabled()
    expect(dialog.getByRole('button', { name: 'Keep' })).toBeEnabled()
  })

  it('pushes with the pick, shows the pushed result and closes on Done', async () => {
    const user = userEvent.setup()
    const sent = renderPush(pushable(), rolledGrit)

    const dialog = await rollGrit(user)
    await user.selectOptions(await dialog.findByLabelText('Pick: Wounds'), 'Tired')
    await user.click(dialog.getByRole('button', { name: 'Push' }))

    expect(await dialog.findByText(/Pushed: Take a Condition/)).toBeInTheDocument()
    expect(sent).toEqual([{ picks: ['tired'] }])
    expect(dialog.getByText(/Rolled Weak, pushed to Strong/)).toBeInTheDocument()
    expect(dialog.queryByRole('button', { name: 'Push' })).not.toBeInTheDocument()
    await user.click(dialog.getByRole('button', { name: 'Done' }))
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
  })

  it('reads the campaign and the journal again after a push, since the cost changes the sheet', async () => {
    const user = userEvent.setup()
    const reads: string[] = []
    renderPush(pushable(), rolledGrit, undefined, reads)

    const dialog = await rollGrit(user)
    await user.selectOptions(await dialog.findByLabelText('Pick: Wounds'), 'Tired')
    const before = { ...countOf(reads) }
    await user.click(dialog.getByRole('button', { name: 'Push' }))

    await waitFor(() => {
      expect(countOf(reads).campaign).toBeGreaterThan(before.campaign)
    })
    expect(countOf(reads).journal).toBeGreaterThan(before.journal)
  })

  it('clears a later pick when an earlier pick of the same field takes its option', async () => {
    const user = userEvent.setup()
    const twoPicks = {
      ...gritCheck,
      push: { label: 'Take two Conditions', picks: ['wounds', 'wounds'], maxDice: null },
    }
    const sent = renderPush({ ...pushable([]), checks: [twoPicks] }, rolledGrit)

    const dialog = await rollGrit(user)
    const [first, second] = await dialog.findAllByLabelText('Pick: Wounds')
    if (first === undefined || second === undefined) {
      throw new Error('The cost asks two picks.')
    }
    await user.selectOptions(second, 'Shaken')
    await user.selectOptions(first, 'Shaken')

    expect(second).toHaveValue('')
    expect(
      within(second)
        .getAllByRole('option')
        .map((option) => option.textContent),
    ).toEqual(['Choose', 'Hurt', 'Tired'])
    expect(dialog.getByRole('button', { name: 'Push' })).toBeDisabled()
    await user.selectOptions(second, 'Tired')
    await user.click(dialog.getByRole('button', { name: 'Push' }))
    await waitFor(() => {
      expect(sent).toEqual([{ picks: ['shaken', 'tired'] }])
    })
  })

  it('keeps the roll and closes on Keep, pushing nothing', async () => {
    const user = userEvent.setup()
    const sent = renderPush(pushable(), rolledGrit)

    const dialog = await rollGrit(user)
    await user.click(await dialog.findByRole('button', { name: 'Keep' }))

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
    expect(sent).toEqual([])
  })

  it('asks no pick of a field with every option marked', async () => {
    const user = userEvent.setup()
    const sent = renderPush(pushable(['hurt', 'shaken', 'tired']), rolledGrit)

    const dialog = await rollGrit(user)
    await user.click(await dialog.findByRole('button', { name: 'Push' }))

    expect(dialog.queryByLabelText('Pick: Wounds')).not.toBeInTheDocument()
    await waitFor(() => {
      expect(sent).toEqual([{ picks: [] }])
    })
  })

  it('shows the refusal of the server and stays on the offer', async () => {
    const user = userEvent.setup()
    renderPush(pushable(), rolledGrit, () =>
      Response.json(
        { error: 'Only the latest entry of the journal can be pushed.' },
        { status: 409 },
      ),
    )

    const dialog = await rollGrit(user)
    await user.selectOptions(await dialog.findByLabelText('Pick: Wounds'), 'Shaken')
    await user.click(dialog.getByRole('button', { name: 'Push' }))

    expect(await dialog.findByRole('alert')).toHaveTextContent(
      'Only the latest entry of the journal can be pushed.',
    )
    expect(dialog.getByRole('button', { name: 'Push' })).toBeEnabled()
  })

  it('offers no push for a roll without a failed die', async () => {
    const user = userEvent.setup()
    const { content } = gritEntry
    if (content.kind !== 'check' || content.pool === null) {
      throw new Error('The fixture is no pool check entry.')
    }
    const dice = content.pool.dice.map((die) => ({ ...die, face: 6, success: true }))
    const allSuccess = recordedEntry({ ...content, pool: { ...content.pool, dice, successes: 3 } })
    renderPush(pushable(), () => Response.json(allSuccess, { status: 201 }))

    await rollGrit(user)

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
  })

  it('closes at once after the roll of a check without a push', async () => {
    const user = userEvent.setup()
    renderPush({ ...pushable(), checks: [skillCheck] }, () =>
      Response.json(recordedEntry(skillCheckEntry.content), { status: 201 }),
    )

    await rollGrit(user)

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
  })
})

/** Vi's campaign whose only check is the burnable "Edge"; the momentum on her sheet is `momentum`. */
function burnable(momentum: number | string | null): Campaign {
  const values: Record<string, unknown> = { ...viCharacter.values }
  if (momentum !== null) {
    values.momentum = momentum
  }
  return {
    ...nightJobCampaign,
    checks: [edgeCheck],
    pinnedRelease: { ...nightJobCampaign.pinnedRelease, sheet: [...sheet, momentumField] },
    characters: [{ ...viCharacter, values: values as Campaign['characters'][number]['values'] }],
  }
}

/**
 * Renders the Play screen on `campaign` (a function: the sheet can change between reads); `push`
 * answers the burn and `sent` collects its bodies.
 */
function renderBurn(
  campaign: () => Campaign,
  push: FakeHandler = () => Response.json(burnedEntry),
  roll: Response = Response.json(burnableRollEntry, { status: 201 }),
) {
  const sent: unknown[] = []
  const reads = { campaign: 0 }
  renderAppAt(`/play/campaigns/${nightJobCampaign.id}`, {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${path}`]: () => {
      reads.campaign += 1
      return Response.json(campaign())
    },
    [`GET ${path}/journal`]: () => Response.json([]),
    [`POST ${path}/journal/checks`]: () => roll.clone(),
    [`POST ${path}/journal/${burnableRollEntry.id}/push`]: async (request: Request) => {
      sent.push(await request.clone().json())
      return push(request)
    },
  })
  return { sent, reads }
}

describe('burning the momentum of a challenge roll from the "Roll a check" dialog (ADR 0049)', () => {
  it('stays open on the roll and offers Burn with the momentum now and the dice it would beat', async () => {
    const user = userEvent.setup()
    renderBurn(() => burnable(8))

    const dialog = await rollGrit(user)

    expect(await dialog.findByText(/^Burn:/)).toBeInTheDocument()
    expect(dialog.getByText(/^Burn:/)).toHaveTextContent('Burn: Burn momentum')
    expect(dialog.getByText(/Momentum now:/)).toHaveTextContent('Momentum now: 8 · score 8')
    expect(
      dialog.getByText('It would beat 2 of 2 challenge dice, against 1 with the roll.'),
    ).toBeInTheDocument()
    expect(dialog.getByRole('button', { name: 'Burn' })).toBeEnabled()
    expect(dialog.getByRole('button', { name: 'Keep' })).toBeEnabled()
    expect(dialog.queryByRole('button', { name: 'Push' })).not.toBeInTheDocument()
    expect(dialog.queryByRole('combobox')).not.toBeInTheDocument()
    expect(dialog.queryByText(/Re-roll/)).not.toBeInTheDocument()
  })

  it('burns, posts to the push endpoint, then shows the burned entry', async () => {
    const user = userEvent.setup()
    const { sent } = renderBurn(() => burnable(8))

    const dialog = await rollGrit(user)
    await user.click(await dialog.findByRole('button', { name: 'Burn' }))

    expect(await dialog.findByText(/Burned: Burn momentum/)).toBeInTheDocument()
    expect(sent).toEqual([{ picks: [] }])
    expect(dialog.getByText(/Rolled Weak, burned to Strong/)).toBeInTheDocument()
    expect(dialog.queryByRole('button', { name: 'Burn' })).not.toBeInTheDocument()
    await user.click(dialog.getByRole('button', { name: 'Done' }))
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
  })

  it('says the score is capped at scoreMax when the momentum exceeds it', async () => {
    const user = userEvent.setup()
    const { content } = burnableRollEntry
    if (content.kind !== 'check' || content.challenge === null) {
      throw new Error('The fixture is no challenge roll.')
    }
    const capped = recordedEntry({
      ...content,
      challenge: { ...content.challenge, scoreMax: 8, momentum: 8 },
    })
    // The pinned check caps at 8 too: a roll of another cap is refused (see `canBurn`).
    const cappedCheck = {
      ...edgeCheck,
      challenge: {
        count: 2,
        sides: 10,
        scoreMax: 8,
        momentumLabel: 'Momentum',
        momentum: 'momentum',
      },
    }
    renderBurn(
      () => ({ ...burnable(10), checks: [cappedCheck] }),
      undefined,
      Response.json(capped, { status: 201 }),
    )

    const dialog = await rollGrit(user)

    expect(await dialog.findByText(/Momentum now:/)).toBeInTheDocument()
    expect(dialog.getByText(/Momentum now:/)).toHaveTextContent(
      'Momentum now: 10 · score 8 (capped at 8)',
    )
  })

  it('offers no Burn when the momentum now no longer beats more dice, and Keep still closes', async () => {
    const user = userEvent.setup()
    const { sent } = renderBurn(() => burnable(6))

    const dialog = await rollGrit(user)

    expect(await dialog.findByText(/Momentum now:/)).toBeInTheDocument()
    expect(dialog.getByText(/Momentum now:/)).toHaveTextContent('Momentum now: 6')
    expect(
      dialog.getByText(
        'It would beat only 1 of 2 challenge dice, against 1 with the roll: burning it would not help.',
      ),
    ).toBeInTheDocument()
    expect(dialog.queryByRole('button', { name: 'Burn' })).not.toBeInTheDocument()
    expect(dialog.queryByRole('combobox')).not.toBeInTheDocument()
    await user.click(dialog.getByRole('button', { name: 'Keep' }))
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
    expect(sent).toEqual([])
  })

  it.each([
    ['the sheet holds no momentum', null],
    ['the momentum is not a number', 'high'],
  ])('fails closed when %s: it cannot be read, and Keep stays', async (_name, momentum) => {
    const user = userEvent.setup()
    renderBurn(() => burnable(momentum))

    const dialog = await rollGrit(user)

    expect(
      await dialog.findByText(
        'Momentum cannot be read from the sheet of the Character, so it cannot be burned.',
      ),
    ).toBeInTheDocument()
    expect(dialog.queryByRole('button', { name: 'Burn' })).not.toBeInTheDocument()
    expect(dialog.getByRole('button', { name: 'Keep' })).toBeEnabled()
  })

  it('shows the refusal of a stale sheet, reads the campaign again and refreshes the offer', async () => {
    const user = userEvent.setup()
    let momentum = 8
    const { reads } = renderBurn(
      () => burnable(momentum),
      () => {
        momentum = 6
        return Response.json(
          { error: 'The momentum no longer beats more challenge dice than the roll.' },
          { status: 409 },
        )
      },
    )

    const dialog = await rollGrit(user)
    const before = reads.campaign
    await user.click(await dialog.findByRole('button', { name: 'Burn' }))

    expect(await dialog.findByRole('alert')).toHaveTextContent(
      'The momentum no longer beats more challenge dice than the roll.',
    )
    await waitFor(() => {
      expect(reads.campaign).toBeGreaterThan(before)
    })
    await waitFor(() => {
      expect(dialog.queryByRole('button', { name: 'Burn' })).not.toBeInTheDocument()
    })
    expect(dialog.getByText(/Momentum now:/)).toHaveTextContent('Momentum now: 6')
    expect(dialog.getByRole('button', { name: 'Keep' })).toBeEnabled()
  })
})

/** How many times the campaign and the journal were read. */
function countOf(reads: string[]) {
  return {
    campaign: reads.filter((read) => read === 'campaign').length,
    journal: reads.filter((read) => read === 'journal').length,
  }
}
