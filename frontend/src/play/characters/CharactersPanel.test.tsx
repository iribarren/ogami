import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { edgeTracker } from '../campaigns/fixtures'
import type { Campaign } from '../campaigns/useCampaigns'
import {
  initiativeField,
  lostMineWithCharacters,
  nightJobCampaign,
  oldCharacter,
  sheet,
  viCharacter,
} from './fixtures'

const path = `/api/campaigns/${nightJobCampaign.id}`

/** The Play screen of `campaign`; `handlers` answer Character requests, recording the bodies sent. */
function renderCharacters(
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

async function characters() {
  return within(await screen.findByRole('region', { name: 'Characters' }))
}

describe('the Characters panel', () => {
  it('lists Characters with Tags and a summary of their values', async () => {
    renderCharacters({
      ...nightJobCampaign,
      characters: [viCharacter, oldCharacter],
    })

    const panel = await characters()
    const [vi, old] = panel.getAllByRole('listitem') as [HTMLElement, HTMLElement]
    expect(within(vi).getByText('Vi')).toBeInTheDocument()
    expect(within(vi).getByText('Leader')).toHaveAttribute('title', 'Runs a faction or a crew.')
    expect(within(vi).getByText('Fixer')).toBeInTheDocument()
    expect(within(vi).getByText(/Role: Solo/)).toBeInTheDocument()
    expect(within(vi).getByText(/HP: 30\/40/)).toBeInTheDocument()
    expect(within(vi).getByText(/Gear: Knife, Agent/)).toBeInTheDocument()
    // A Character without values reads as the field defaults.
    expect(within(old).getByText(/Reflexes: 5/)).toBeInTheDocument()
    expect(within(old).getByText(/HP: 40\/40/)).toBeInTheDocument()
    expect(within(old).getByText(/Humanity: 0\/0/)).toBeInTheDocument()
  })

  it('shows the empty state', async () => {
    renderCharacters({ ...nightJobCampaign, characters: [] })

    expect((await characters()).getByText('No Characters yet.')).toBeInTheDocument()
  })

  it('offers only the Tags that apply to Characters', async () => {
    const user = userEvent.setup()
    const villain = { key: 'villain', label: 'Villain', hint: null, appliesTo: ['npc' as const] }
    const hero = { key: 'hero', label: 'Hero', hint: null, appliesTo: ['character' as const] }
    renderCharacters({
      ...lostMineWithCharacters,
      characters: [],
      tags: [...lostMineWithCharacters.tags, villain, hero],
    })

    const panel = await characters()
    await user.click(panel.getByRole('button', { name: 'Add Character' }))

    expect(panel.getAllByRole('checkbox').map((box) => box.getAttribute('value'))).toEqual([
      'leader',
      'ally',
      'hero',
    ])
  })

  it('adds a Character with a name and Tags when the release has no sheet fields', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    const { server } = renderCharacters(
      { ...lostMineWithCharacters, characters: [] },
      {
        [`POST ${path}/characters`]: () => {
          server.campaign = { ...server.campaign, characters: [viCharacter] }
          return Response.json(viCharacter, { status: 201 })
        },
      },
      sent,
    )

    const panel = await characters()
    await user.click(panel.getByRole('button', { name: 'Add Character' }))
    expect(panel.queryByRole('group', { name: 'Stats' })).not.toBeInTheDocument()
    await user.type(panel.getByLabelText('Name'), 'Vi')
    await user.click(panel.getByRole('checkbox', { name: /Leader/ }))
    await user.type(panel.getByLabelText('Free tags'), 'Fixer{Enter}')
    await user.click(panel.getByRole('button', { name: 'Save' }))

    expect(await panel.findByText('Fixer')).toBeInTheDocument()
    expect(sent).toEqual([{ name: 'Vi', tags: ['leader'], freeTags: ['Fixer'], values: {} }])
    expect(panel.queryByLabelText('Name')).not.toBeInTheDocument()
  })

  it('renders the sheet fields by group with their defaults and sends every value', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    renderCharacters(
      { ...nightJobCampaign, characters: [] },
      { [`POST ${path}/characters`]: () => Response.json(viCharacter, { status: 201 }) },
      sent,
    )

    const panel = await characters()
    await user.click(panel.getByRole('button', { name: 'Add Character' }))
    // Consecutive fields with the same group render together; a field without one stands alone.
    const stats = panel.getByRole('group', { name: 'Stats' })
    expect(within(stats).getByLabelText('Reflexes')).toHaveValue(5)
    expect(within(stats).getByLabelText('Body')).toHaveValue(4)
    expect(within(stats).getByLabelText('Reflexes')).toHaveAttribute('min', '1')
    expect(within(stats).getByLabelText('Reflexes')).toHaveAttribute('max', '8')
    expect(within(stats).getByText('How fast you react.')).toBeInTheDocument()
    const vitals = panel.getByRole('group', { name: 'Vitals' })
    // A fixed max starts full and cannot be edited; a free one starts at 0.
    expect(within(vitals).getByLabelText('HP current')).toHaveValue(40)
    expect(within(vitals).getByLabelText('HP max')).toHaveValue(40)
    expect(within(vitals).getByLabelText('HP max')).toHaveAttribute('readonly')
    expect(within(vitals).getByLabelText('Humanity max')).not.toHaveAttribute('readonly')
    expect(panel.getByLabelText('Notes').tagName).toBe('TEXTAREA')
    expect(panel.getByLabelText('Role').tagName).toBe('INPUT')
    expect(panel.getByLabelText('Role')).toHaveAttribute('maxlength', '40')

    await user.type(panel.getByLabelText('Name'), 'Vi')
    await user.type(panel.getByLabelText('Role'), 'Solo')
    await user.clear(panel.getByLabelText('Reflexes'))
    await user.type(panel.getByLabelText('Reflexes'), '7')
    await user.clear(panel.getByLabelText('HP current'))
    await user.type(panel.getByLabelText('HP current'), '30')
    await user.clear(panel.getByLabelText('Humanity max'))
    await user.type(panel.getByLabelText('Humanity max'), '50')
    await user.clear(panel.getByLabelText('Humanity current'))
    await user.type(panel.getByLabelText('Humanity current'), '20')
    await user.click(panel.getByRole('button', { name: 'Add Item' }))
    await user.type(panel.getByLabelText('Item 1'), 'Knife')
    await user.click(panel.getByRole('button', { name: 'Add Item' }))
    await user.click(panel.getByRole('button', { name: 'Add Item' }))
    await user.type(panel.getByLabelText('Item 3'), 'Agent')
    await user.click(panel.getByRole('button', { name: 'Remove Item 2' }))
    await user.type(panel.getByLabelText('Notes'), 'Owes a favor.')
    await user.click(panel.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(sent).toEqual([
        {
          name: 'Vi',
          tags: [],
          freeTags: [],
          values: {
            role: 'Solo',
            reflexes: 7,
            body: 4,
            hp: { current: 30, max: 40 },
            humanity: { current: 20, max: 50 },
            gear: ['Knife', 'Agent'],
            notes: 'Owes a favor.',
          },
        },
      ])
    })
  })

  it('stops adding list items at the field maximum', async () => {
    const user = userEvent.setup()
    renderCharacters(nightJobCampaign)

    const panel = await characters()
    await user.click(panel.getByRole('button', { name: 'Edit Vi' }))
    await user.click(panel.getByRole('button', { name: 'Add Item' }))

    expect(panel.getByLabelText('Item 3')).toBeInTheDocument()
    expect(panel.getByRole('button', { name: 'Add Item' })).toBeDisabled()
  })

  it('opens the form prefilled to edit and sends the whole Character', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    renderCharacters(
      nightJobCampaign,
      { [`PUT ${path}/characters/${viCharacter.id}`]: () => Response.json(viCharacter) },
      sent,
    )

    const panel = await characters()
    await user.click(panel.getByRole('button', { name: 'Edit Vi' }))
    expect(panel.getByLabelText('Name')).toHaveValue('Vi')
    expect(panel.getByRole('checkbox', { name: /Leader/ })).toBeChecked()
    expect(panel.getByRole('button', { name: 'Remove free tag Fixer' })).toBeInTheDocument()
    expect(panel.getByLabelText('Role')).toHaveValue('Solo')
    expect(panel.getByLabelText('Humanity max')).toHaveValue(50)
    expect(panel.getByLabelText('Item 2')).toHaveValue('Agent')

    await user.clear(panel.getByLabelText('Name'))
    await user.type(panel.getByLabelText('Name'), 'Violet')
    await user.click(panel.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(sent).toEqual([
        { name: 'Violet', tags: ['leader'], freeTags: ['Fixer'], values: viCharacter.values },
      ])
    })
  })

  it('leaves derived fields out of the form and sends no value for them', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    renderCharacters(
      {
        ...nightJobCampaign,
        pinnedRelease: { ...nightJobCampaign.pinnedRelease, sheet: [...sheet, initiativeField] },
      },
      { [`PUT ${path}/characters/${viCharacter.id}`]: () => Response.json(viCharacter) },
      sent,
    )

    const panel = await characters()
    await user.click(panel.getByRole('button', { name: 'Edit Vi' }))
    // Shown read-only, as an output: not a field to type in, and never sent.
    expect(panel.getByLabelText('Initiative').tagName).toBe('OUTPUT')
    expect(panel.queryByRole('spinbutton', { name: 'Initiative' })).not.toBeInTheDocument()
    await user.click(panel.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(sent).toEqual([
        { name: 'Vi', tags: ['leader'], freeTags: ['Fixer'], values: viCharacter.values },
      ])
    })
  })

  describe('derived fields', () => {
    const withDerived = (formula: string, trackers: Campaign['trackers'] = []): Campaign => ({
      ...nightJobCampaign,
      trackers,
      pinnedRelease: {
        ...nightJobCampaign.pinnedRelease,
        sheet: [...sheet, { ...initiativeField, formula }],
      },
    })

    async function editVi() {
      const user = userEvent.setup()
      const panel = await characters()
      await user.click(panel.getByRole('button', { name: 'Edit Vi' }))
      return { user, panel }
    }

    it('shows the value, the hint and the formula, and recomputes as the player types', async () => {
      renderCharacters(withDerived('{reflexes} + {body}'))

      const { user, panel } = await editVi()
      expect(panel.getByLabelText('Initiative')).toHaveTextContent('13')
      expect(panel.getByText('Reflexes plus body.')).toBeInTheDocument()
      expect(panel.getByText('{reflexes} + {body}')).toBeInTheDocument()
      await user.clear(panel.getByLabelText('Reflexes'))
      await user.type(panel.getByLabelText('Reflexes'), '8')
      expect(panel.getByLabelText('Initiative')).toHaveTextContent('14')
    })

    it('lays a derived field out in its group, in sheet order', async () => {
      renderCharacters({
        ...nightJobCampaign,
        pinnedRelease: {
          ...nightJobCampaign.pinnedRelease,
          sheet: [...sheet.slice(0, 3), initiativeField, ...sheet.slice(3)],
        },
      })

      const { panel } = await editVi()
      const stats = within(panel.getByRole('group', { name: 'Stats' }))
      expect(stats.getAllByRole('spinbutton')).toHaveLength(2)
      expect(stats.getByLabelText('Initiative')).toBeInTheDocument()
      expect(panel.getAllByRole('group', { name: 'Stats' })).toHaveLength(1)
    })

    it('reads the Campaign trackers', async () => {
      renderCharacters(withDerived('{reflexes} + {tracker:edge}', [edgeTracker]))

      const { panel } = await editVi()
      expect(panel.getByLabelText('Initiative')).toHaveTextContent('8')
    })

    it('shows a dash with the reason when the formula fails or a value is incomplete', async () => {
      renderCharacters(withDerived('{body} / ({reflexes} - 7)'))

      const { user, panel } = await editVi()
      expect(panel.getByLabelText('Initiative')).toHaveTextContent('— (division by zero)')
      await user.clear(panel.getByLabelText('Reflexes'))
      expect(panel.getByLabelText('Initiative')).toHaveTextContent('— (incomplete value)')
    })

    it('shows a dash for a formula that does not parse', async () => {
      renderCharacters(withDerived('{reflexes} +'))

      const { panel } = await editVi()
      expect(panel.getByLabelText('Initiative')).toHaveTextContent('— (invalid formula)')
    })

    it("summarises derived fields from the server's values, a dash for none", async () => {
      renderCharacters({
        ...withDerived('{reflexes} + {body}'),
        characters: [
          { ...viCharacter, derived: { initiative: 13 } },
          { ...oldCharacter, derived: { initiative: null } },
        ],
      })

      const [vi, old] = (await characters()).getAllByRole('listitem') as [HTMLElement, HTMLElement]
      expect(within(vi).getByText(/Initiative: 13/)).toBeInTheDocument()
      expect(within(old).getByText(/Initiative: —/)).toBeInTheDocument()
    })
  })

  it('edits a Character saved before the sheet gained fields with the defaults filled in', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    renderCharacters(
      { ...nightJobCampaign, characters: [oldCharacter] },
      { [`PUT ${path}/characters/${oldCharacter.id}`]: () => Response.json(oldCharacter) },
      sent,
    )

    const panel = await characters()
    await user.click(panel.getByRole('button', { name: 'Edit Old hand' }))
    expect(panel.getByLabelText('Reflexes')).toHaveValue(5)
    await user.click(panel.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(sent).toEqual([
        {
          name: 'Old hand',
          tags: [],
          freeTags: [],
          values: {
            role: '',
            reflexes: 5,
            body: 4,
            hp: { current: 40, max: 40 },
            humanity: { current: 0, max: 0 },
            gear: [],
            notes: '',
          },
        },
      ])
    })
  })

  it('shows the refusal of the API and keeps the form as typed', async () => {
    const user = userEvent.setup()
    renderCharacters(nightJobCampaign, {
      [`PUT ${path}/characters/${viCharacter.id}`]: () =>
        Response.json({ error: 'Field "reflexes" must be 1 to 8, got 9.' }, { status: 422 }),
    })

    const panel = await characters()
    await user.click(panel.getByRole('button', { name: 'Edit Vi' }))
    await user.clear(panel.getByLabelText('Name'))
    await user.type(panel.getByLabelText('Name'), 'Violet')
    await user.click(panel.getByRole('button', { name: 'Save' }))

    expect(await panel.findByRole('alert')).toHaveTextContent('Field "reflexes" must be 1 to 8')
    expect(panel.getByLabelText('Name')).toHaveValue('Violet')
  })

  it('shows a conflict as the API words it and refetches the campaign', async () => {
    const user = userEvent.setup()
    let reads = 0
    renderCharacters(nightJobCampaign, {
      [`PUT ${path}/characters/${viCharacter.id}`]: () =>
        Response.json({ error: 'Campaign was changed by another request.' }, { status: 409 }),
      [`GET ${path}`]: () => {
        reads += 1
        return Response.json(nightJobCampaign)
      },
    })

    const panel = await characters()
    await user.click(panel.getByRole('button', { name: 'Edit Vi' }))
    await user.click(panel.getByRole('button', { name: 'Save' }))

    expect(await panel.findByRole('alert')).toHaveTextContent('changed by another request')
    await waitFor(() => {
      expect(reads).toBeGreaterThan(1)
    })
  })

  it('cancels a form without saving', async () => {
    const user = userEvent.setup()
    renderCharacters(nightJobCampaign)

    const panel = await characters()
    await user.click(panel.getByRole('button', { name: 'Add Character' }))
    await user.click(panel.getByRole('button', { name: 'Cancel' }))

    expect(panel.queryByLabelText('Name')).not.toBeInTheDocument()
  })
})
