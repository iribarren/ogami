import { screen, waitFor, within, type BoundFunctions, type queries } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { lostMineCampaign } from '../campaigns/fixtures'
import { lostMineWithFacts, oathFact } from '../facts/fixtures'
import type { Campaign } from '../campaigns/useCampaigns'
import {
  brannNpc,
  ledgerThread,
  lostMineWithLists,
  miraNpc,
  oldGuardNpc,
  vaultThread,
} from './fixtures'

const path = `/api/campaigns/${lostMineWithLists.id}`

/** The Play screen of `campaign`; `handlers` answer list requests, recording the bodies sent. */
function renderLists(
  campaign: Campaign,
  handlers: Record<string, FakeHandler> = {},
  sent: unknown[] = [],
) {
  const server = { campaign }
  const result = renderAppAt(`/play/campaigns/${campaign.id}`, {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${path}`]: () => Response.json(server.campaign),
    [`GET ${path}/journal`]: () => Response.json([]),
    ...Object.fromEntries(
      Object.entries(handlers).map(([route, handler]) => [
        route,
        async (request: Request) => {
          const text = await request.clone().text()
          sent.push(text === '' ? null : JSON.parse(text))
          return handler(request)
        },
      ]),
    ),
  })
  return { ...result, server }
}

async function npcs() {
  return within(await screen.findByRole('region', { name: 'NPCs' }))
}

async function threads() {
  return within(await screen.findByRole('region', { name: 'Threads' }))
}

/** The collapsed "Closed (n)" part of a panel. */
function closedSection(panel: BoundFunctions<typeof queries>) {
  const details = panel.getByText('Closed (1)').closest('details')
  if (details === null) {
    throw new Error('The Closed summary is not in a details element.')
  }
  return details
}

describe('the NPCs panel', () => {
  it('lists open NPCs with agenda, weight and tags, and closed ones under Closed', async () => {
    renderLists(lostMineWithLists)

    const panel = await npcs()
    const [mira, brann] = panel.getAllByRole('listitem') as [HTMLElement, HTMLElement]
    expect(within(mira).getByText('Mira Vane')).toBeInTheDocument()
    expect(within(mira).getByText('Wants the ledger back.')).toBeInTheDocument()
    expect(within(mira).getByText('Weight 2')).toBeInTheDocument()
    // The release label shows, with the hint as a tooltip; a free tag shows as typed.
    expect(within(mira).getByText('Leader')).toHaveAttribute('title', 'Runs a faction or a crew.')
    expect(within(mira).getByText('Fence')).toBeInTheDocument()
    expect(within(brann).getByText('Brann')).toBeInTheDocument()
    expect(within(brann).queryByText(/Weight/)).not.toBeInTheDocument()

    const closed = closedSection(panel)
    expect(within(closed).getByText('The old guard')).toBeInTheDocument()
    expect(within(closed).getByRole('button', { name: 'Reopen The old guard' })).toBeInTheDocument()
  })

  it('shows a tag key missing from the release as it is', async () => {
    renderLists({ ...lostMineWithLists, npcs: [{ ...miraNpc, tags: ['gone'] }] })

    expect((await npcs()).getByText('gone')).toBeInTheDocument()
  })

  it('shows the empty state for a campaign without NPCs', async () => {
    renderLists(lostMineCampaign)

    const panel = await npcs()
    expect(panel.getByText('No open NPCs yet.')).toBeInTheDocument()
    expect(panel.queryByText(/Closed/)).not.toBeInTheDocument()
  })

  it('shows the panels without a current scene', async () => {
    renderLists({ ...lostMineWithLists, currentSceneNumber: null })

    expect(await npcs()).toBeDefined()
    expect(await threads()).toBeDefined()
  })

  it('adds an NPC with every field and shows it after the refetch', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    const { server } = renderLists(
      lostMineWithLists,
      {
        [`POST ${path}/npcs`]: () => {
          server.campaign = { ...server.campaign, npcs: [...server.campaign.npcs, newNpc] }
          return Response.json(newNpc, { status: 201 })
        },
      },
      sent,
    )
    const newNpc = {
      ...brannNpc,
      id: 'new-npc',
      name: 'Kess',
      agenda: 'Wants out.',
      tags: ['ally'],
      freeTags: ['Pilot', 'Tall'],
      weight: 3,
    }

    const panel = await npcs()
    await user.click(panel.getByRole('button', { name: 'Add NPC' }))
    await user.type(panel.getByLabelText('Name'), 'Kess')
    await user.type(panel.getByLabelText('Agenda'), 'Wants out.')
    await user.click(panel.getByRole('checkbox', { name: /Ally/ }))
    await user.type(panel.getByLabelText('Free tags'), ' Pilot ')
    await user.click(panel.getByRole('button', { name: 'Add free tag' }))
    await user.type(panel.getByLabelText('Free tags'), '   {Enter}')
    await user.type(panel.getByLabelText('Free tags'), 'Tall{Enter}')
    await user.selectOptions(panel.getByLabelText('Weight'), '3')
    await user.click(panel.getByRole('button', { name: 'Save' }))

    expect(await panel.findByText('Kess')).toBeInTheDocument()
    expect(sent).toEqual([
      {
        name: 'Kess',
        agenda: 'Wants out.',
        tags: ['ally'],
        freeTags: ['Pilot', 'Tall'],
        weight: 3,
      },
    ])
    expect(panel.queryByLabelText('Name')).not.toBeInTheDocument()
  })

  it('sends a blank agenda as null', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    renderLists(
      lostMineWithLists,
      { [`POST ${path}/npcs`]: () => Response.json(brannNpc, { status: 201 }) },
      sent,
    )

    const panel = await npcs()
    await user.click(panel.getByRole('button', { name: 'Add NPC' }))
    await user.type(panel.getByLabelText('Name'), 'Kess')
    await user.click(panel.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(sent).toEqual([{ name: 'Kess', agenda: null, tags: [], freeTags: [], weight: 1 }])
    })
  })

  it('opens the form prefilled to edit and replaces the NPC in place', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    renderLists(
      lostMineWithLists,
      {
        [`PUT ${path}/npcs/${miraNpc.id}`]: () =>
          Response.json({ ...miraNpc, name: 'Mira V.', weight: 1 }),
      },
      sent,
    )

    const panel = await npcs()
    await user.click(panel.getByRole('button', { name: 'Edit Mira Vane' }))
    expect(panel.getByLabelText('Name')).toHaveValue('Mira Vane')
    expect(panel.getByLabelText('Agenda')).toHaveValue('Wants the ledger back.')
    expect(panel.getByRole('checkbox', { name: /Leader/ })).toBeChecked()
    expect(panel.getByRole('checkbox', { name: /Ally/ })).not.toBeChecked()
    expect(panel.getByRole('button', { name: 'Remove free tag Fence' })).toBeInTheDocument()
    expect(panel.getByLabelText('Weight')).toHaveValue('2')

    await user.clear(panel.getByLabelText('Name'))
    await user.type(panel.getByLabelText('Name'), 'Mira V.')
    await user.selectOptions(panel.getByLabelText('Weight'), '1')
    await user.click(panel.getByRole('button', { name: 'Save' }))

    expect(await panel.findByText('Mira V.')).toBeInTheDocument()
    expect(sent).toEqual([
      {
        name: 'Mira V.',
        agenda: 'Wants the ledger back.',
        tags: ['leader'],
        freeTags: ['Fence'],
        weight: 1,
      },
    ])
    expect(panel.queryByLabelText('Name')).not.toBeInTheDocument()
  })

  it('sends a free tag holding a comma back unchanged when saved untouched', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    const smith = { ...miraNpc, freeTags: ['Smith, John', 'Fence'] }
    renderLists(
      { ...lostMineWithLists, npcs: [smith] },
      { [`PUT ${path}/npcs/${miraNpc.id}`]: () => Response.json(smith) },
      sent,
    )

    const panel = await npcs()
    await user.click(panel.getByRole('button', { name: 'Edit Mira Vane' }))
    await user.click(panel.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(sent).toEqual([
        {
          name: 'Mira Vane',
          agenda: 'Wants the ledger back.',
          tags: ['leader'],
          freeTags: ['Smith, John', 'Fence'],
          weight: 2,
        },
      ])
    })
  })

  it('adds a free tag as one tag, commas included, and removes another', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    renderLists(
      lostMineWithLists,
      { [`PUT ${path}/npcs/${miraNpc.id}`]: () => Response.json(miraNpc) },
      sent,
    )

    const panel = await npcs()
    await user.click(panel.getByRole('button', { name: 'Edit Mira Vane' }))
    await user.type(panel.getByLabelText('Free tags'), 'Doe, Jane{Enter}')
    expect(panel.getByRole('button', { name: 'Remove free tag Doe, Jane' })).toBeInTheDocument()
    expect(panel.getByLabelText('Free tags')).toHaveValue('')
    await user.click(panel.getByRole('button', { name: 'Remove free tag Fence' }))
    await user.click(panel.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(sent).toEqual([expect.objectContaining({ freeTags: ['Doe, Jane'] })])
    })
  })

  it('keeps a tag the release does not know ticked while editing', async () => {
    const user = userEvent.setup()
    renderLists({ ...lostMineWithLists, npcs: [{ ...miraNpc, tags: ['gone'] }] })

    const panel = await npcs()
    await user.click(panel.getByRole('button', { name: 'Edit Mira Vane' }))

    expect(panel.getByRole('checkbox', { name: /gone/ })).toBeChecked()
  })

  it('closes and reopens an NPC through their own endpoints and refetches', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    const { server, fakeApi } = renderLists(
      lostMineWithLists,
      {
        [`POST ${path}/npcs/${brannNpc.id}/close`]: () => {
          server.campaign = {
            ...server.campaign,
            npcs: [miraNpc, { ...brannNpc, status: 'closed' }, oldGuardNpc],
          }
          return Response.json({ ...brannNpc, status: 'closed' })
        },
        [`POST ${path}/npcs/${oldGuardNpc.id}/reopen`]: () => {
          server.campaign = {
            ...server.campaign,
            npcs: [miraNpc, brannNpc, { ...oldGuardNpc, status: 'open' }],
          }
          return Response.json({ ...oldGuardNpc, status: 'open' })
        },
      },
      sent,
    )

    const panel = await npcs()
    await user.click(panel.getByRole('button', { name: 'Close Brann' }))
    expect(await panel.findByRole('button', { name: 'Reopen Brann' })).toBeInTheDocument()
    await user.click(panel.getByRole('button', { name: 'Reopen The old guard' }))
    expect(await panel.findByRole('button', { name: 'Close The old guard' })).toBeInTheDocument()

    expect(fakeApi.requests).toContain(`POST ${path}/npcs/${brannNpc.id}/close`)
    expect(fakeApi.requests).toContain(`POST ${path}/npcs/${oldGuardNpc.id}/reopen`)
  })

  it('shows why an add was refused and keeps the form with what was typed', async () => {
    const user = userEvent.setup()
    renderLists(lostMineWithLists, {
      [`POST ${path}/npcs`]: () =>
        Response.json({ error: 'The name is too long.' }, { status: 422 }),
    })

    const panel = await npcs()
    await user.click(panel.getByRole('button', { name: 'Add NPC' }))
    await user.type(panel.getByLabelText('Name'), 'Kess')
    await user.type(panel.getByLabelText('Free tags'), 'Pilot')
    await user.click(panel.getByRole('button', { name: 'Save' }))

    expect(await panel.findByRole('alert')).toHaveTextContent('The name is too long.')
    expect(panel.getByLabelText('Name')).toHaveValue('Kess')
    expect(panel.getByLabelText('Free tags')).toHaveValue('Pilot')
  })

  it('shows why a close was refused', async () => {
    const user = userEvent.setup()
    renderLists(lostMineWithLists, {
      [`POST ${path}/npcs/${brannNpc.id}/close`]: () =>
        Response.json({ error: 'NPC not found.' }, { status: 404 }),
    })

    const panel = await npcs()
    await user.click(panel.getByRole('button', { name: 'Close Brann' }))

    expect(await panel.findByRole('alert')).toHaveTextContent('NPC not found.')
  })

  it('refetches the campaign when a change is refused with 409', async () => {
    const user = userEvent.setup()
    const { server } = renderLists(lostMineWithLists, {
      [`PUT ${path}/npcs/${miraNpc.id}`]: () =>
        Response.json({ error: 'Modified meanwhile.' }, { status: 409 }),
    })

    const panel = await npcs()
    server.campaign = { ...lostMineWithLists, npcs: [{ ...miraNpc, name: 'Mira, renamed' }] }
    await user.click(panel.getByRole('button', { name: 'Edit Mira Vane' }))
    await user.click(panel.getByRole('button', { name: 'Save' }))

    expect(await panel.findByText('Modified meanwhile.')).toBeInTheDocument()
    await waitFor(() => {
      expect(screen.getByLabelText('Name')).toBeInTheDocument()
    })
    await user.click(panel.getByRole('button', { name: 'Cancel' }))
    expect(await panel.findByText('Mira, renamed')).toBeInTheDocument()
  })

  it('hides the Tags field when the release has no Tags', async () => {
    const user = userEvent.setup()
    renderLists({ ...lostMineWithLists, tags: [] })

    const panel = await npcs()
    await user.click(panel.getByRole('button', { name: 'Add NPC' }))

    expect(panel.getByLabelText('Name')).toBeInTheDocument()
    expect(panel.queryByRole('checkbox')).not.toBeInTheDocument()
  })

  it('offers only the Tags that apply to NPCs, and keeps a held one that no longer does', async () => {
    const user = userEvent.setup()
    const goal = { key: 'goal', label: 'Goal', hint: null, appliesTo: ['thread' as const] }
    const villain = { key: 'villain', label: 'Villain', hint: null, appliesTo: ['npc' as const] }
    const hero = {
      key: 'hero',
      label: 'Hero',
      hint: 'The player side.',
      appliesTo: ['character' as const],
    }
    const tags = [...lostMineWithLists.tags, goal, villain, hero]
    const sent: unknown[] = []
    renderLists(
      {
        ...lostMineWithLists,
        tags,
        npcs: [{ ...miraNpc, tags: ['hero'] }, brannNpc],
      },
      { [`POST ${path}/npcs`]: () => Response.json({}, { status: 201 }) },
      sent,
    )

    const panel = await npcs()
    await user.click(panel.getByRole('button', { name: 'Add NPC' }))

    const offered = panel.getAllByRole('checkbox').map((box) => box.getAttribute('value'))
    expect(offered).toEqual(['leader', 'ally', 'villain'])

    await user.click(panel.getByRole('button', { name: 'Cancel' }))
    await user.click(panel.getByRole('button', { name: 'Edit Mira Vane' }))
    const held = panel.getAllByRole('checkbox').map((box) => box.getAttribute('value'))
    expect(held).toEqual(['leader', 'ally', 'villain', 'hero'])
    expect(panel.getByRole('checkbox', { name: /Hero/ })).toBeChecked()
  })

  it('offers only the Tags that apply to Threads', async () => {
    const user = userEvent.setup()
    const goal = { key: 'goal', label: 'Goal', hint: null, appliesTo: ['thread' as const] }
    const villain = { key: 'villain', label: 'Villain', hint: null, appliesTo: ['npc' as const] }
    renderLists({ ...lostMineWithLists, tags: [...lostMineWithLists.tags, goal, villain] })

    const panel = await threads()
    await user.click(panel.getByRole('button', { name: 'Add Thread' }))

    expect(panel.getAllByRole('checkbox').map((box) => box.getAttribute('value'))).toEqual([
      'leader',
      'ally',
      'goal',
    ])
  })

  it('cancels the form and keeps one form open at a time', async () => {
    const user = userEvent.setup()
    renderLists(lostMineWithLists)

    const panel = await npcs()
    await user.click(panel.getByRole('button', { name: 'Add NPC' }))
    await user.click(panel.getByRole('button', { name: 'Edit Brann' }))
    expect(panel.getAllByLabelText('Name')).toHaveLength(1)
    expect(panel.getByLabelText('Name')).toHaveValue('Brann')

    await user.click(panel.getByRole('button', { name: 'Cancel' }))
    expect(panel.queryByLabelText('Name')).not.toBeInTheDocument()
  })
})

describe('the Threads panel', () => {
  it('lists open and closed Threads without an agenda field', async () => {
    const user = userEvent.setup()
    renderLists(lostMineWithLists)

    const panel = await threads()
    expect(panel.getByText('Rob the vault')).toBeInTheDocument()
    expect(panel.getByText('Weight 3')).toBeInTheDocument()
    expect(panel.getByText('Vault')).toBeInTheDocument()
    expect(within(closedSection(panel)).getByText(ledgerThread.name)).toBeInTheDocument()

    await user.click(panel.getByRole('button', { name: 'Add Thread' }))
    expect(panel.getByLabelText('Name')).toHaveAttribute('maxlength', '200')
    expect(panel.queryByLabelText('Agenda')).not.toBeInTheDocument()
  })

  it('shows the empty state for a campaign without Threads', async () => {
    renderLists(lostMineCampaign)

    expect((await threads()).getByText('No open Threads yet.')).toBeInTheDocument()
  })

  it('adds, edits, closes and reopens a Thread through the Thread endpoints', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    renderLists(
      lostMineWithLists,
      {
        [`POST ${path}/threads`]: () => Response.json(vaultThread, { status: 201 }),
        [`PUT ${path}/threads/${vaultThread.id}`]: () =>
          Response.json({ ...vaultThread, name: 'Rob the bank' }),
        [`POST ${path}/threads/${vaultThread.id}/close`]: () =>
          Response.json({ ...vaultThread, status: 'closed' }),
        [`POST ${path}/threads/${ledgerThread.id}/reopen`]: () =>
          Response.json({ ...ledgerThread, status: 'open' }),
      },
      sent,
    )

    const panel = await threads()
    await user.click(panel.getByRole('button', { name: 'Add Thread' }))
    await user.type(panel.getByLabelText('Name'), 'Escape')
    await user.click(panel.getByRole('button', { name: 'Save' }))
    await waitFor(() => {
      expect(panel.queryByLabelText('Name')).not.toBeInTheDocument()
    })

    await user.click(panel.getByRole('button', { name: 'Edit Rob the vault' }))
    await user.clear(panel.getByLabelText('Name'))
    await user.type(panel.getByLabelText('Name'), 'Rob the bank')
    await user.click(panel.getByRole('button', { name: 'Save' }))
    expect(await panel.findByText('Rob the bank')).toBeInTheDocument()

    await user.click(panel.getByRole('button', { name: 'Close Rob the bank' }))
    await user.click(panel.getByRole('button', { name: 'Reopen Find the ledger' }))

    await waitFor(() => {
      expect(sent).toHaveLength(4)
    })
    expect(sent[0]).toEqual({ name: 'Escape', tags: [], freeTags: [], weight: 1 })
    expect(sent[1]).toEqual({
      name: 'Rob the bank',
      tags: ['leader'],
      freeTags: ['Vault'],
      weight: 3,
    })
  })
})

describe('the facts linked to an item', () => {
  it('shows, on an NPC row, the facts that link it and the slot facts it is the subject of', async () => {
    renderLists(lostMineWithFacts)

    const [mira, brann] = (await npcs()).getAllByRole('listitem') as [HTMLElement, HTMLElement]
    // The city links Mira Vane; the Prince slot holds her.
    expect(within(mira).getByText('Facts: The city, The Prince')).toBeInTheDocument()
    expect(within(brann).queryByText(/Facts:/)).not.toBeInTheDocument()
  })

  it('shows them on a Thread row, closed ones too', async () => {
    const closedLinked = {
      ...lostMineWithFacts,
      facts: [
        ...lostMineWithFacts.facts,
        {
          ...oathFact,
          links: [{ kind: 'thread' as const, id: ledgerThread.id, name: ledgerThread.name }],
        },
      ],
    }
    renderLists(closedLinked)

    const panel = await threads()
    expect(panel.getByText('Facts: The guild')).toBeInTheDocument()
    expect(panel.getByText('Facts: The oath')).toBeInTheDocument()
  })

  it('does not mistake an NPC for a Thread of the same id', async () => {
    renderLists({
      ...lostMineWithFacts,
      facts: [{ ...oathFact, links: [{ kind: 'thread', id: miraNpc.id, name: 'Mira Vane' }] }],
    })

    expect((await npcs()).queryByText(/Facts:/)).not.toBeInTheDocument()
  })

  it('shows no facts line for a campaign without facts', async () => {
    renderLists(lostMineWithLists)

    await npcs()
    expect(screen.queryByText(/Facts:/)).not.toBeInTheDocument()
  })
})

describe('promoting an NPC to a Character', () => {
  const promotePath = `${path}/npcs/${miraNpc.id}/promote`
  const promoted = { ...miraNpc, status: 'closed' as const, promotedTo: 'character-1' }

  it('asks to confirm, promotes through the API and shows the NPC as promoted', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    const { server } = renderLists(
      lostMineWithLists,
      {
        [`POST ${promotePath}`]: () => {
          server.campaign = { ...server.campaign, npcs: [promoted, brannNpc, oldGuardNpc] }
          return Response.json({ id: 'character-1' }, { status: 201 })
        },
      },
      sent,
    )

    const panel = await npcs()
    await user.click(panel.getByRole('button', { name: 'Promote Mira Vane to Character' }))
    // Nothing is sent before the confirmation.
    expect(sent).toEqual([])
    await user.click(panel.getByRole('button', { name: 'Keep as NPC' }))
    expect(panel.queryByRole('button', { name: 'Confirm promotion' })).not.toBeInTheDocument()

    await user.click(panel.getByRole('button', { name: 'Promote Mira Vane to Character' }))
    await user.click(panel.getByRole('button', { name: 'Confirm promotion' }))

    await waitFor(() => {
      expect(panel.getByText('Closed (2)')).toBeInTheDocument()
    })
    expect(sent).toEqual([null])
    expect(panel.getByText('Promoted')).toBeInTheDocument()
    expect(panel.queryByRole('button', { name: /Promote Mira Vane/ })).not.toBeInTheDocument()
    expect(panel.queryByRole('button', { name: 'Reopen Mira Vane' })).not.toBeInTheDocument()
  })

  it('promotes a closed NPC and offers no promotion on Threads', async () => {
    const user = userEvent.setup()
    renderLists(lostMineWithLists, {
      [`POST ${path}/npcs/${oldGuardNpc.id}/promote`]: () =>
        Response.json({ id: 'character-1' }, { status: 201 }),
    })

    const panel = await npcs()
    await user.click(panel.getByRole('button', { name: 'Promote The old guard to Character' }))
    expect(panel.getByRole('button', { name: 'Confirm promotion' })).toBeInTheDocument()
    expect((await threads()).queryByRole('button', { name: /Promote/ })).not.toBeInTheDocument()
  })

  it('reads an NPC saved before promotions (no promotedTo) as not promoted', async () => {
    const legacy: Partial<typeof miraNpc> = { ...miraNpc }
    delete legacy.promotedTo
    renderLists({ ...lostMineWithLists, npcs: [legacy as typeof miraNpc] })

    const panel = await npcs()
    expect(
      panel.getByRole('button', { name: 'Promote Mira Vane to Character' }),
    ).toBeInTheDocument()
    expect(panel.queryByText('Promoted')).not.toBeInTheDocument()
  })

  it('shows a refusal and refetches the campaign after a conflict', async () => {
    const user = userEvent.setup()
    const { server } = renderLists(lostMineWithLists, {
      [`POST ${promotePath}`]: () => {
        server.campaign = { ...server.campaign, npcs: [promoted, brannNpc, oldGuardNpc] }
        return Response.json(
          { error: `NPC "${miraNpc.id}" was already promoted to a Character.` },
          { status: 409 },
        )
      },
    })

    const panel = await npcs()
    await user.click(panel.getByRole('button', { name: 'Promote Mira Vane to Character' }))
    await user.click(panel.getByRole('button', { name: 'Confirm promotion' }))

    expect(await panel.findByRole('alert')).toHaveTextContent('already promoted')
    expect(await panel.findByText('Promoted')).toBeInTheDocument()
    // The refetch shows the NPC promoted, so the confirmation has nothing left to ask.
    expect(panel.queryByRole('button', { name: 'Confirm promotion' })).not.toBeInTheDocument()
  })

  it('shows an unprocessable promotion as the API words it', async () => {
    const user = userEvent.setup()
    renderLists(lostMineWithLists, {
      [`POST ${promotePath}`]: () =>
        Response.json({ error: 'Tag "leader" not found in the pinned release.' }, { status: 422 }),
    })

    const panel = await npcs()
    await user.click(panel.getByRole('button', { name: 'Promote Mira Vane to Character' }))
    await user.click(panel.getByRole('button', { name: 'Confirm promotion' }))

    expect(await panel.findByRole('alert')).toHaveTextContent('Tag "leader" not found')
    // The confirmation stays open beside the refusal; only a success closes it.
    expect(panel.getByRole('button', { name: 'Confirm promotion' })).toBeEnabled()
  })

  it('shows a refused promotion over an older refused close', async () => {
    const user = userEvent.setup()
    renderLists(lostMineWithLists, {
      [`POST ${path}/npcs/${brannNpc.id}/close`]: () =>
        Response.json({ error: 'NPC not found.' }, { status: 404 }),
      [`POST ${promotePath}`]: () =>
        Response.json({ error: 'Tag "leader" not found in the pinned release.' }, { status: 422 }),
    })

    const panel = await npcs()
    await user.click(panel.getByRole('button', { name: 'Close Brann' }))
    expect(await panel.findByRole('alert')).toHaveTextContent('NPC not found.')

    await user.click(panel.getByRole('button', { name: 'Promote Mira Vane to Character' }))
    await user.click(panel.getByRole('button', { name: 'Confirm promotion' }))

    expect(await panel.findByRole('alert')).toHaveTextContent('Tag "leader" not found')
    expect(panel.getAllByRole('alert')).toHaveLength(1)
  })

  it('disables every promotion button while a promotion is pending', async () => {
    const user = userEvent.setup()
    let release: () => void = () => undefined
    const gate = new Promise<void>((resolve) => {
      release = resolve
    })
    const { server } = renderLists(lostMineWithLists, {
      [`POST ${promotePath}`]: async () => {
        await gate
        server.campaign = { ...server.campaign, npcs: [promoted, brannNpc, oldGuardNpc] }
        return Response.json({ id: 'character-1' }, { status: 201 })
      },
    })

    const panel = await npcs()
    await user.click(panel.getByRole('button', { name: 'Promote Mira Vane to Character' }))
    await user.click(panel.getByRole('button', { name: 'Confirm promotion' }))

    expect(panel.getByRole('button', { name: 'Confirm promotion' })).toBeDisabled()
    expect(panel.getByRole('button', { name: 'Promote Brann to Character' })).toBeDisabled()

    release()
    await waitFor(() => {
      expect(panel.queryByRole('button', { name: 'Confirm promotion' })).not.toBeInTheDocument()
    })
    expect(panel.getByRole('button', { name: 'Promote Brann to Character' })).toBeEnabled()
  })
})
