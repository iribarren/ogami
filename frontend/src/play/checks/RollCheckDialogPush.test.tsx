import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { gritCheck, skillCheck } from '../campaigns/fixtures'
import type { Campaign } from '../campaigns/useCampaigns'
import { conditionsField, nightJobCampaign, sheet, viCharacter } from '../characters/fixtures'
import { gritEntry, pushedGritEntry, recordedEntry, skillCheckEntry } from '../journal/fixtures'

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
      push: { label: 'Take two Conditions', picks: ['wounds', 'wounds'] },
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

/** How many times the campaign and the journal were read. */
function countOf(reads: string[]) {
  return {
    campaign: reads.filter((read) => read === 'campaign').length,
    journal: reads.filter((read) => read === 'journal').length,
  }
}
