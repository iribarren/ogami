import { screen, waitFor, within, type BoundFunctions, type queries } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { lostMineCampaign } from '../campaigns/fixtures'
import type { Campaign } from '../campaigns/useCampaigns'
import {
  brannNpc,
  dunmerePlace,
  ledgerThread,
  miraNpc,
  oldGuardNpc,
  saltmarshPlace,
  vaultThread,
} from '../lists/fixtures'
import {
  cityFact,
  goalSlot,
  guildFact,
  lostMineWithFacts,
  oathFact,
  princeFact,
  siteFact,
  siteSlot,
} from './fixtures'

const path = `/api/campaigns/${lostMineWithFacts.id}`

/** The Play screen of `campaign`; `handlers` answer the fact requests, recording the bodies sent. */
function renderFacts(
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
          sent.push(JSON.parse(await request.clone().text()))
          return handler(request)
        },
      ]),
    ),
  })
  return { ...result, server }
}

type Row = BoundFunctions<typeof queries>

async function facts() {
  return within(await screen.findByRole('region', { name: 'Facts' }))
}

describe('the Facts panel', () => {
  it('lists the slots in release order with hints and values, then the free facts', async () => {
    renderFacts(lostMineWithFacts)

    const panel = await facts()
    const rows = panel.getAllByRole('listitem')
    expect(rows).toHaveLength(5)
    const [city, prince, goal, guild, oath] = rows.map((row) => within(row)) as [
      Row,
      Row,
      Row,
      Row,
      Row,
    ]
    expect(city.getByText('The city')).toBeInTheDocument()
    expect(city.getByText('Where does the story begin?')).toBeInTheDocument()
    expect(city.getByText('Ashford')).toBeInTheDocument()
    expect(city.getByText('Links: Mira Vane (NPC)')).toBeInTheDocument()
    // An NPC slot shows its subject's current name.
    expect(prince.getByText('Mira Vane')).toBeInTheDocument()
    expect(goal.getByText('What does the character want?')).toBeInTheDocument()
    expect(goal.getByText('Not filled')).toBeInTheDocument()
    expect(guild.getByText('Owes a debt to the Prince.')).toBeInTheDocument()
    expect(guild.getByText('Links: Rob the vault (Thread)')).toBeInTheDocument()
    expect(oath.getByText('Never steal from the poor.')).toBeInTheDocument()
  })

  it('shows the empty state for a campaign from before the facts', async () => {
    renderFacts(lostMineCampaign)

    const panel = await facts()
    expect(panel.getByText('No facts yet.')).toBeInTheDocument()
    expect(panel.getByRole('button', { name: 'Add fact' })).toBeInTheDocument()
  })

  it('fills a text slot again with the text only', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    const { server } = renderFacts(
      lostMineWithFacts,
      {
        [`PUT ${path}/fact-slots/city`]: () => {
          const refilled = { ...cityFact, text: 'Prague', value: 'Prague' }
          server.campaign = {
            ...server.campaign,
            facts: [refilled, ...server.campaign.facts.slice(1)],
          }
          return Response.json(refilled)
        },
      },
      sent,
    )

    const panel = await facts()
    await user.click(panel.getByRole('button', { name: 'Refill The city' }))
    const form = within(panel.getByRole('form', { name: 'Fill The city' }))
    expect(form.getByLabelText('Text')).toHaveValue('Ashford')
    await user.clear(form.getByLabelText('Text'))
    expect(form.getByRole('button', { name: 'Save' })).toBeDisabled()
    await user.type(form.getByLabelText('Text'), '  Prague ')
    await user.click(form.getByRole('button', { name: 'Save' }))

    expect(await panel.findByText('Prague')).toBeInTheDocument()
    expect(sent).toEqual([{ text: 'Prague' }])
    expect(panel.queryByRole('form')).not.toBeInTheDocument()
  })

  it('fills an NPC slot with an existing NPC, a closed one too', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    renderFacts(
      lostMineWithFacts,
      { [`PUT ${path}/fact-slots/prince`]: () => Response.json(princeFact) },
      sent,
    )

    const panel = await facts()
    await user.click(panel.getByRole('button', { name: 'Refill The Prince' }))
    const form = within(panel.getByRole('form', { name: 'Fill The Prince' }))
    expect(form.getByRole('option', { name: 'The old guard (closed)' })).toBeInTheDocument()
    await user.selectOptions(form.getByLabelText('NPC'), oldGuardNpc.id)
    expect(form.queryByLabelText('New NPC name')).not.toBeInTheDocument()
    await user.click(form.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(sent).toEqual([{ subject: oldGuardNpc.id }])
    })
  })

  it('fills a Thread slot with a new Thread by name', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    renderFacts(
      lostMineWithFacts,
      { [`PUT ${path}/fact-slots/goal`]: () => Response.json(princeFact) },
      sent,
    )

    const panel = await facts()
    await user.click(panel.getByRole('button', { name: `Fill ${goalSlot.label}` }))
    const form = within(panel.getByRole('form', { name: 'Fill The goal' }))
    expect(form.getByRole('option', { name: `${ledgerThread.name} (closed)` })).toBeInTheDocument()
    expect(form.getByRole('button', { name: 'Save' })).toBeDisabled()
    await user.type(form.getByLabelText('New Thread name'), ' Find the heir ')
    await user.click(form.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(sent).toEqual([{ name: 'Find the heir' }])
    })
  })

  it('fills a Place slot with an existing Place, a closed one too, or a new one by name', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    renderFacts(
      { ...lostMineWithFacts, factSlots: [siteSlot], facts: [] },
      { [`PUT ${path}/fact-slots/site`]: () => Response.json(siteFact) },
      sent,
    )

    const panel = await facts()
    await user.click(panel.getByRole('button', { name: `Fill ${siteSlot.label}` }))
    const form = within(panel.getByRole('form', { name: 'Fill The site' }))
    expect(form.getByRole('option', { name: 'Saltmarsh' })).toBeInTheDocument()
    expect(form.getByRole('option', { name: 'Dunmere (closed)' })).toBeInTheDocument()
    await user.selectOptions(form.getByLabelText('Place'), dunmerePlace.id)
    await user.click(form.getByRole('button', { name: 'Save' }))
    await waitFor(() => {
      expect(sent).toEqual([{ subject: dunmerePlace.id }])
    })

    await user.click(panel.getByRole('button', { name: `Fill ${siteSlot.label}` }))
    const again = within(panel.getByRole('form', { name: 'Fill The site' }))
    expect(again.getByLabelText('New Place name')).toHaveAttribute('maxlength', '200')
    await user.type(again.getByLabelText('New Place name'), ' The keep ')
    await user.click(again.getByRole('button', { name: 'Save' }))
    await waitFor(() => {
      expect(sent).toEqual([{ subject: dunmerePlace.id }, { name: 'The keep' }])
    })
  })

  it('shows the name of the Place filling a slot', async () => {
    renderFacts({ ...lostMineWithFacts, factSlots: [siteSlot], facts: [siteFact] })

    expect((await facts()).getByText(saltmarshPlace.name)).toBeInTheDocument()
  })

  it('links a fact to an open or closed Place and tells it from an NPC with the same id', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    const twin = { ...miraNpc, id: saltmarshPlace.id, name: 'Not the place' }
    renderFacts(
      { ...lostMineWithFacts, npcs: [...lostMineWithFacts.npcs, twin] },
      { [`PUT ${path}/facts/${oathFact.id}`]: () => Response.json(oathFact) },
      sent,
    )

    const panel = await facts()
    await user.click(panel.getByRole('button', { name: 'Edit links of The oath' }))
    const form = within(panel.getByRole('form', { name: 'Links of The oath' }))
    expect(form.getByRole('option', { name: 'Place: Dunmere' })).toBeInTheDocument()
    await user.selectOptions(form.getByLabelText('Link to'), `place:${saltmarshPlace.id}`)
    await user.click(form.getByRole('button', { name: 'Add link' }))
    expect(form.getByText('Saltmarsh (Place)')).toBeInTheDocument()
    expect(form.getByRole('option', { name: 'NPC: Not the place' })).toBeInTheDocument()
    await user.click(form.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(sent).toEqual([
        {
          label: 'The oath',
          text: 'Never steal from the poor.',
          links: [{ kind: 'place', id: saltmarshPlace.id }],
        },
      ])
    })
  })

  it('adds a free fact and shows it after the refetch', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    const added = {
      ...oathFact,
      id: 'new-fact',
      label: 'The debt',
      text: 'Ten crowns.',
      value: 'Ten crowns.',
    }
    const { server } = renderFacts(
      lostMineWithFacts,
      {
        [`POST ${path}/facts`]: () => {
          server.campaign = { ...server.campaign, facts: [...server.campaign.facts, added] }
          return Response.json(added, { status: 201 })
        },
      },
      sent,
    )

    const panel = await facts()
    await user.click(await panel.findByRole('button', { name: 'Add fact' }))
    const form = within(panel.getByRole('form', { name: 'Add fact' }))
    expect(form.getByRole('button', { name: 'Save' })).toBeDisabled()
    await user.type(form.getByLabelText('Label'), ' The debt ')
    await user.type(form.getByLabelText('Text'), 'Ten crowns.')
    await user.click(form.getByRole('button', { name: 'Save' }))

    expect(await panel.findByText('Ten crowns.')).toBeInTheDocument()
    expect(sent).toEqual([{ label: 'The debt', text: 'Ten crowns.', links: [] }])
  })

  it('edits the label and text of a free fact, keeping its links', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    renderFacts(
      lostMineWithFacts,
      { [`PUT ${path}/facts/${guildFact.id}`]: () => Response.json(guildFact) },
      sent,
    )

    const panel = await facts()
    await user.click(panel.getByRole('button', { name: 'Edit The guild' }))
    const form = within(panel.getByRole('form', { name: 'Edit The guild' }))
    await user.clear(form.getByLabelText('Label'))
    await user.type(form.getByLabelText('Label'), 'The thieves guild')
    await user.click(form.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(sent).toEqual([
        {
          label: 'The thieves guild',
          text: 'Owes a debt to the Prince.',
          links: [{ kind: 'thread', id: vaultThread.id }],
        },
      ])
    })
  })

  it('edits the links of a slot fact and sends only the links', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    renderFacts(
      lostMineWithFacts,
      { [`PUT ${path}/facts/${cityFact.id}`]: () => Response.json(cityFact) },
      sent,
    )

    const panel = await facts()
    await user.click(panel.getByRole('button', { name: 'Edit links of The city' }))
    const form = within(panel.getByRole('form', { name: 'Links of The city' }))
    // The fact itself and the link it already has are not offered; closed items and other facts are.
    expect(form.queryByRole('option', { name: 'Fact: The city' })).not.toBeInTheDocument()
    expect(form.queryByRole('option', { name: 'NPC: Mira Vane' })).not.toBeInTheDocument()
    await user.selectOptions(form.getByLabelText('Link to'), `thread:${ledgerThread.id}`)
    await user.click(form.getByRole('button', { name: 'Add link' }))
    await user.selectOptions(form.getByLabelText('Link to'), `fact:${oathFact.id}`)
    await user.click(form.getByRole('button', { name: 'Add link' }))
    await user.click(form.getByRole('button', { name: `Remove link ${miraNpc.name}` }))
    await user.click(form.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(sent).toEqual([
        {
          links: [
            { kind: 'thread', id: ledgerThread.id },
            { kind: 'fact', id: oathFact.id },
          ],
        },
      ])
    })
  })

  it('tells an NPC and a Thread with the same id apart when linking', async () => {
    const user = userEvent.setup()
    const twin = { ...vaultThread, id: miraNpc.id, name: 'Mira, the thread' }
    renderFacts({ ...lostMineWithFacts, threads: [...lostMineWithFacts.threads, twin] })

    const panel = await facts()
    await user.click(panel.getByRole('button', { name: 'Edit links of The oath' }))
    const form = within(panel.getByRole('form', { name: 'Links of The oath' }))
    await user.selectOptions(form.getByLabelText('Link to'), `npc:${miraNpc.id}`)
    await user.click(form.getByRole('button', { name: 'Add link' }))

    // Linking the NPC leaves the Thread with the same id on offer.
    expect(form.getByRole('option', { name: 'Thread: Mira, the thread' })).toBeInTheDocument()
    expect(form.queryByRole('option', { name: 'NPC: Mira Vane' })).not.toBeInTheDocument()
    await user.selectOptions(form.getByLabelText('Link to'), `thread:${miraNpc.id}`)
    await user.click(form.getByRole('button', { name: 'Add link' }))

    // Removing one of the two removes only that one.
    await user.click(form.getByRole('button', { name: `Remove link ${miraNpc.name}` }))
    expect(form.getByRole('button', { name: 'Remove link Mira, the thread' })).toBeInTheDocument()
    expect(form.getByRole('option', { name: 'NPC: Mira Vane' })).toBeInTheDocument()
  })

  it('stops offering links at the limit of 20', async () => {
    const user = userEvent.setup()
    const many = Array.from({ length: 20 }, (_, index) => ({
      kind: 'fact' as const,
      id: `fact-${String(index)}`,
      name: `Fact ${String(index)}`,
    }))
    renderFacts({ ...lostMineWithFacts, facts: [{ ...oathFact, links: many }] })

    const panel = await facts()
    await user.click(panel.getByRole('button', { name: 'Edit links of The oath' }))
    const form = within(panel.getByRole('form', { name: 'Links of The oath' }))

    expect(form.getByLabelText('Link to')).toBeDisabled()
    expect(form.getByRole('button', { name: 'Add link' })).toBeDisabled()
    expect(form.getByText('A fact takes at most 20 links.')).toBeInTheDocument()
  })

  it('shows why a fill was refused and keeps the form as typed', async () => {
    const user = userEvent.setup()
    renderFacts(lostMineWithFacts, {
      [`PUT ${path}/fact-slots/city`]: () =>
        Response.json({ error: 'The fact text is too long.' }, { status: 422 }),
    })

    const panel = await facts()
    await user.click(panel.getByRole('button', { name: 'Refill The city' }))
    const form = within(panel.getByRole('form', { name: 'Fill The city' }))
    await user.click(form.getByRole('button', { name: 'Save' }))

    expect(await form.findByRole('alert')).toHaveTextContent('The fact text is too long.')
    expect(form.getByLabelText('Text')).toHaveValue('Ashford')
  })

  it('refetches the campaign when a change is refused with 409', async () => {
    const user = userEvent.setup()
    const { server } = renderFacts(lostMineWithFacts, {
      [`PUT ${path}/facts/${oathFact.id}`]: () =>
        Response.json({ error: 'Modified meanwhile.' }, { status: 409 }),
    })

    const panel = await facts()
    server.campaign = {
      ...lostMineWithFacts,
      facts: lostMineWithFacts.facts.map((fact) =>
        fact.id === oathFact.id
          ? { ...fact, text: 'Always pay the debt.', value: 'Always pay the debt.' }
          : fact,
      ),
    }
    await user.click(panel.getByRole('button', { name: 'Edit The oath' }))
    await user.click(panel.getByRole('button', { name: 'Save' }))

    expect(await panel.findByText('Modified meanwhile.')).toBeInTheDocument()
    await user.click(panel.getByRole('button', { name: 'Cancel' }))
    expect(await panel.findByText('Always pay the debt.')).toBeInTheDocument()
  })

  it('offers every NPC to fill an NPC slot', async () => {
    const user = userEvent.setup()
    renderFacts(lostMineWithFacts)

    const panel = await facts()
    await user.click(await panel.findByRole('button', { name: 'Refill The Prince' }))

    const names = within(panel.getByLabelText('NPC'))
      .getAllByRole('option')
      .map(({ textContent }) => textContent)
    expect(names).toEqual(['New NPC…', miraNpc.name, brannNpc.name, 'The old guard (closed)'])
  })
})
