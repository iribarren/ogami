import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { edgeTracker } from '../campaigns/fixtures'
import type { Campaign } from '../campaigns/useCampaigns'
import {
  conditionsField,
  creationBudget,
  creationCampaign,
  creationSheet,
  twoBudgetCampaign,
  initiativeField,
  lostMineWithCharacters,
  nightJobCampaign,
  oldCharacter,
  sheet,
  viCharacter,
} from './fixtures'

// Radix checkboxes in a form render a hidden input whose size it measures.
vi.stubGlobal(
  'ResizeObserver',
  class {
    observe = vi.fn()
    unobserve = vi.fn()
    disconnect = vi.fn()
  },
)

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

    it('shows the value and the hint, not the formula, and recomputes as the player types', async () => {
      renderCharacters(withDerived('{reflexes} + {body}'))

      const { user, panel } = await editVi()
      expect(panel.getByLabelText('Initiative')).toHaveTextContent('13')
      expect(panel.getByText('Reflexes plus body.')).toBeInTheDocument()
      expect(panel.queryByText('{reflexes} + {body}')).not.toBeInTheDocument()
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

  describe('conditions fields', () => {
    const tiredVi = { ...viCharacter, values: { ...viCharacter.values, wounds: ['tired'] } }
    const withWounds: Campaign = {
      ...nightJobCampaign,
      pinnedRelease: { ...nightJobCampaign.pinnedRelease, sheet: [...sheet, conditionsField] },
      characters: [tiredVi, oldCharacter],
    }

    it('summarizes the marks, and a Character without the field reads as none marked', async () => {
      renderCharacters(withWounds)

      const [vi, old] = (await characters()).getAllByRole('listitem') as [HTMLElement, HTMLElement]
      expect(within(vi).getByText(/Wounds: Tired/)).toBeInTheDocument()
      expect(within(old).getByText(/Wounds: none/)).toBeInTheDocument()
    })

    it('marks and clears options in the form and sends the marked keys with the whole Character', async () => {
      const user = userEvent.setup()
      const sent: unknown[] = []
      renderCharacters(
        withWounds,
        { [`PUT ${path}/characters/${tiredVi.id}`]: () => Response.json(tiredVi) },
        sent,
      )

      const panel = await characters()
      await user.click(panel.getByRole('button', { name: 'Edit Vi' }))
      expect(panel.getByRole('checkbox', { name: 'Tired' })).toBeChecked()
      expect(panel.getByRole('checkbox', { name: 'Hurt' })).not.toBeChecked()
      await user.click(panel.getByRole('checkbox', { name: 'Hurt' }))
      await user.click(panel.getByRole('checkbox', { name: 'Tired' }))
      await user.click(panel.getByRole('button', { name: 'Save' }))

      await waitFor(() => {
        expect(sent).toEqual([
          {
            name: 'Vi',
            tags: ['leader'],
            freeTags: ['Fixer'],
            values: { ...viCharacter.values, wounds: ['hurt'] },
          },
        ])
      })
    })

    it('starts a new Character with nothing marked and sends an empty list', async () => {
      const user = userEvent.setup()
      const sent: unknown[] = []
      const { server } = renderCharacters(
        { ...withWounds, characters: [] },
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
      for (const box of panel.getAllByRole('checkbox', { name: /Hurt|Shaken|Tired/ })) {
        expect(box).not.toBeChecked()
      }
      await user.type(panel.getByLabelText('Name'), 'Vi')
      await user.click(panel.getByRole('button', { name: 'Save' }))

      await waitFor(() => {
        expect(sent).toHaveLength(1)
      })
      expect((sent[0] as { values: Record<string, unknown> }).values.wounds).toEqual([])
    })

    it('shows the refusal of an option the API does not know', async () => {
      const user = userEvent.setup()
      renderCharacters(withWounds, {
        [`PUT ${path}/characters/${tiredVi.id}`]: () =>
          Response.json({ error: 'Field "wounds" has no option "ghost".' }, { status: 422 }),
      })

      const panel = await characters()
      await user.click(panel.getByRole('button', { name: 'Edit Vi' }))
      await user.click(panel.getByRole('button', { name: 'Save' }))

      expect(await panel.findByRole('alert')).toHaveTextContent(
        'Field "wounds" has no option "ghost".',
      )
      expect(panel.getByRole('checkbox', { name: 'Tired' })).toBeChecked()
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

describe('creating a Character by the rules of the sheet', () => {
  const created = { ...viCharacter, values: {} }

  /** Adds a Character on `campaign`, recording the bodies sent; the form is returned. */
  async function addCharacter(campaign: Campaign, sent: unknown[] = []) {
    const user = userEvent.setup()
    renderCharacters(
      campaign,
      { [`POST ${path}/characters`]: () => Response.json(created, { status: 201 }) },
      sent,
    )
    const panel = await characters()
    await user.click(panel.getByRole('button', { name: 'Add Character' }))
    await user.type(panel.getByLabelText('Name'), 'Vi')
    return { user, panel }
  }

  const bodyOf = (sent: unknown[]) => (sent[0] ?? {}) as { method?: unknown; values: object }

  it('needs a method when the budget covers a rolled field, and sends the rolled path as roll', async () => {
    const sent: unknown[] = []
    const { user, panel } = await addCharacter(creationCampaign, sent)

    expect(
      panel.getByRole('radiogroup', { name: 'How do you create this Character?' }),
    ).toBeVisible()
    expect(panel.getByRole('button', { name: 'Save' })).toBeDisabled()
    await user.click(panel.getByRole('radio', { name: 'Roll' }))
    // Every dice field is shown as set by the server, with its dice; none has a control.
    expect(panel.getByLabelText('Strength')).toHaveTextContent('Rolled at creation: 3d6*5')
    expect(panel.getByLabelText('Constitution')).toHaveTextContent('Rolled at creation: (2d6+6)*5')
    expect(panel.getByLabelText('Luck')).toHaveTextContent('Rolled at creation: 3d6*5')
    expect(panel.getByLabelText('Hit points')).toHaveTextContent(
      'Set at creation: ({str} + {con}) / 10',
    )
    expect(panel.queryByText(/Characteristic points:/)).not.toBeInTheDocument()
    await user.type(panel.getByLabelText('Role'), 'Spy')
    await user.click(panel.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(sent).toEqual([
        { name: 'Vi', tags: [], freeTags: [], values: { role: 'Spy' }, method: 'roll' },
      ])
    })
  })

  it('sends the spent values by points and leaves the rolls outside the budget to the server', async () => {
    const sent: unknown[] = []
    const { user, panel } = await addCharacter(creationCampaign, sent)

    await user.click(panel.getByRole('radio', { name: 'Spend points' }))
    // Budget fields become inputs bounded by the budget; Luck, outside it, is still rolled.
    const strength = panel.getByLabelText('Strength')
    expect(strength).toHaveAttribute('min', '15')
    expect(strength).toHaveAttribute('max', '90')
    expect(panel.getByLabelText('Luck')).toHaveTextContent('Rolled at creation: 3d6*5')
    expect(panel.getByText('Characteristic points: 30 / 100')).toBeInTheDocument()
    await user.clear(strength)
    await user.type(strength, '40')
    await user.clear(panel.getByLabelText('Constitution'))
    await user.type(panel.getByLabelText('Constitution'), '60')
    expect(panel.getByText('Characteristic points: 100 / 100')).toBeInTheDocument()
    await user.click(panel.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(sent).toEqual([
        {
          name: 'Vi',
          tags: [],
          freeTags: [],
          values: { role: '', str: 40, con: 60 },
          method: 'points',
        },
      ])
    })
  })

  it('turns the counter to an error and disables Save when more than the total is spent', async () => {
    const { user, panel } = await addCharacter(creationCampaign)

    await user.click(panel.getByRole('radio', { name: 'Spend points' }))
    await user.clear(panel.getByLabelText('Strength'))
    await user.type(panel.getByLabelText('Strength'), '60')
    await user.clear(panel.getByLabelText('Constitution'))
    await user.type(panel.getByLabelText('Constitution'), '50')

    const counter = panel.getByText('Characteristic points: 110 / 100 (over budget)')
    expect(counter).toHaveClass('text-destructive')
    expect(panel.getByRole('button', { name: 'Save' })).toBeDisabled()
    await user.clear(panel.getByLabelText('Constitution'))
    await user.type(panel.getByLabelText('Constitution'), '40')
    expect(panel.getByRole('button', { name: 'Save' })).toBeEnabled()
  })

  it('spends an unrolled budget without a choice, labelled Points by default', async () => {
    const sent: unknown[] = []
    const plain = creationSheet.map((field) => ({ ...field, roll: null }))
    const { user, panel } = await addCharacter(
      {
        ...creationCampaign,
        pinnedRelease: {
          ...creationCampaign.pinnedRelease,
          sheet: plain,
          pointBudgets: [{ key: 'points', ...creationBudget, label: null }],
        },
      },
      sent,
    )

    expect(panel.queryByRole('radiogroup')).not.toBeInTheDocument()
    expect(panel.getByText('Points: 30 / 100')).toBeInTheDocument()
    expect(panel.getByLabelText('Luck')).toHaveValue(1)
    await user.click(panel.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(bodyOf(sent)).toMatchObject({
        method: 'points',
        values: { str: 15, con: 15, luck: 1 },
      })
    })
  })

  it('has the player set the non-dice budget fields of a mixed budget by roll, without a counter', async () => {
    const sent: unknown[] = []
    const sheet = creationSheet.map((field) =>
      field.key === 'con' ? { ...field, roll: null } : field,
    )
    const { user, panel } = await addCharacter(
      { ...creationCampaign, pinnedRelease: { ...creationCampaign.pinnedRelease, sheet } },
      sent,
    )

    await user.click(panel.getByRole('radio', { name: 'Roll' }))
    expect(panel.getByLabelText('Strength')).toHaveTextContent('Rolled at creation: 3d6*5')
    const constitution = panel.getByLabelText('Constitution')
    expect(constitution).toHaveAttribute('min', '15')
    expect(constitution).toHaveAttribute('max', '90')
    expect(panel.queryByText(/Characteristic points:/)).not.toBeInTheDocument()
    await user.clear(constitution)
    await user.type(constitution, '80')
    await user.click(panel.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(bodyOf(sent)).toMatchObject({ method: 'roll', values: { con: 80 } })
    })
    expect(bodyOf(sent).values).not.toHaveProperty('str')
  })

  it('gives each budget its own counter, spending only the unrollable one by roll', async () => {
    const sent: unknown[] = []
    const { user, panel } = await addCharacter(twoBudgetCampaign, sent)

    await user.click(panel.getByRole('radio', { name: 'Roll' }))
    // The characteristics budget is rollable: rolled, no counter. Skill points are still spent.
    expect(panel.getByLabelText('Strength')).toHaveTextContent('Rolled at creation: 3d6*5')
    expect(panel.queryByText(/Characteristic points:/)).not.toBeInTheDocument()
    expect(panel.getByText('Skill points: 0 / 8')).toBeInTheDocument()
    const lore = panel.getByLabelText('Lore')
    expect(lore).toHaveAttribute('max', '3')
    await user.clear(lore)
    await user.type(lore, '3')
    expect(panel.getByText('Skill points: 3 / 8')).toBeInTheDocument()
    await user.click(panel.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(bodyOf(sent)).toMatchObject({ method: 'roll', values: { lore: 3 } })
    })
    expect(bodyOf(sent).values).not.toHaveProperty('str')
  })

  it('spends every budget by points, each bounded by its own budget', async () => {
    const sent: unknown[] = []
    const { user, panel } = await addCharacter(twoBudgetCampaign, sent)

    await user.click(panel.getByRole('radio', { name: 'Spend points' }))
    expect(panel.getByText('Characteristic points: 30 / 100')).toBeInTheDocument()
    expect(panel.getByText('Skill points: 0 / 8')).toBeInTheDocument()
    expect(panel.getByLabelText('Strength')).toHaveAttribute('max', '90')
    expect(panel.getByLabelText('Lore')).toHaveAttribute('max', '3')
    expect(panel.getByLabelText('Lore')).not.toHaveAttribute('min', '15')
    await user.clear(panel.getByLabelText('Lore'))
    await user.type(panel.getByLabelText('Lore'), '2')
    await user.click(panel.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(bodyOf(sent)).toMatchObject({
        method: 'points',
        values: { str: 15, con: 15, lore: 2 },
      })
    })
  })

  it('blocks Save while a budget is over, by points', async () => {
    const { user, panel } = await addCharacter(twoBudgetCampaign)

    await user.click(panel.getByRole('radio', { name: 'Spend points' }))
    await user.clear(panel.getByLabelText('Strength'))
    await user.type(panel.getByLabelText('Strength'), '90')
    await user.clear(panel.getByLabelText('Constitution'))
    await user.type(panel.getByLabelText('Constitution'), '20')
    expect(panel.getByText('Characteristic points: 110 / 100 (over budget)')).toHaveClass(
      'text-destructive',
    )
    expect(panel.getByText('Skill points: 0 / 8')).not.toHaveClass('text-destructive')
    expect(panel.getByRole('button', { name: 'Save' })).toBeDisabled()
    await user.clear(panel.getByLabelText('Constitution'))
    await user.type(panel.getByLabelText('Constitution'), '15')
    expect(panel.getByText('Characteristic points: 105 / 100 (over budget)')).toBeInTheDocument()
    expect(panel.getByRole('button', { name: 'Save' })).toBeDisabled()
    await user.clear(panel.getByLabelText('Strength'))
    await user.type(panel.getByLabelText('Strength'), '85')
    expect(panel.getByText('Characteristic points: 100 / 100')).toBeInTheDocument()
    expect(panel.getByRole('button', { name: 'Save' })).toBeEnabled()
  })

  it('blocks Save while the unrollable budget is over, by roll', async () => {
    const campaign: typeof twoBudgetCampaign = {
      ...twoBudgetCampaign,
      pinnedRelease: {
        ...twoBudgetCampaign.pinnedRelease,
        pointBudgets: [
          { key: 'points', ...creationBudget },
          {
            key: 'skills',
            label: 'Skill points',
            total: 2,
            fields: ['lore'],
            min: null,
            max: null,
          },
        ],
      },
    }
    const { user, panel } = await addCharacter(campaign)

    await user.click(panel.getByRole('radio', { name: 'Roll' }))
    await user.clear(panel.getByLabelText('Lore'))
    await user.type(panel.getByLabelText('Lore'), '3')

    expect(panel.getByText('Skill points: 3 / 2 (over budget)')).toBeInTheDocument()
    expect(panel.getByRole('button', { name: 'Save' })).toBeDisabled()
  })

  it('needs a method before it can be saved when only one budget is rollable', async () => {
    const { panel } = await addCharacter(twoBudgetCampaign)

    expect(panel.getByRole('radiogroup')).toBeVisible()
    expect(panel.getByRole('button', { name: 'Save' })).toBeDisabled()
  })

  it('has no method choice when no budget is rollable, and spends every budget', async () => {
    const sent: unknown[] = []
    const plain = twoBudgetCampaign.pinnedRelease.sheet.map((field) => ({ ...field, roll: null }))
    const { user, panel } = await addCharacter(
      {
        ...twoBudgetCampaign,
        pinnedRelease: { ...twoBudgetCampaign.pinnedRelease, sheet: plain },
      },
      sent,
    )

    expect(panel.queryByRole('radiogroup')).not.toBeInTheDocument()
    expect(panel.getByText('Characteristic points: 30 / 100')).toBeInTheDocument()
    expect(panel.getByText('Skill points: 0 / 8')).toBeInTheDocument()
    await user.click(panel.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(bodyOf(sent)).toMatchObject({ method: 'points' })
    })
  })

  it('creates a sheet without a budget as before, with no method, but still leaves rolled fields to the server', async () => {
    const sent: unknown[] = []
    const { user, panel } = await addCharacter(
      {
        ...creationCampaign,
        pinnedRelease: { ...creationCampaign.pinnedRelease, pointBudgets: [] },
      },
      sent,
    )

    expect(panel.queryByRole('radiogroup')).not.toBeInTheDocument()
    expect(panel.getByLabelText('Strength')).toHaveTextContent('Rolled at creation: 3d6*5')
    await user.click(panel.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(sent).toEqual([{ name: 'Vi', tags: [], freeTags: [], values: { role: '' } }])
    })
  })

  it('edits a Character with every field as an input and no method or counter', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    renderCharacters(
      {
        ...creationCampaign,
        characters: [
          {
            ...viCharacter,
            values: { role: 'Spy', str: 50, con: 60, luck: 45, hp: { current: 5, max: 11 } },
          },
        ],
      },
      { [`PUT ${path}/characters/${viCharacter.id}`]: () => Response.json(viCharacter) },
      sent,
    )

    const panel = await characters()
    await user.click(panel.getByRole('button', { name: 'Edit Vi' }))

    expect(panel.queryByRole('radiogroup')).not.toBeInTheDocument()
    expect(panel.queryByText(/Characteristic points:/)).not.toBeInTheDocument()
    expect(panel.getByLabelText('Strength')).toHaveValue(50)
    expect(panel.getByLabelText('Strength')).toHaveAttribute('max', '99')
    expect(panel.getByLabelText('Luck')).toHaveValue(45)
    expect(panel.getByLabelText('Hit points max')).not.toHaveAttribute('readonly')
    await user.click(panel.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(sent).toEqual([
        {
          name: 'Vi',
          tags: ['leader'],
          freeTags: ['Fixer'],
          values: { role: 'Spy', str: 50, con: 60, luck: 45, hp: { current: 5, max: 11 } },
        },
      ])
    })
  })

  it('shows the message of a refused creation and keeps the form', async () => {
    const user = userEvent.setup()
    renderCharacters(creationCampaign, {
      [`POST ${path}/characters`]: () =>
        Response.json(
          {
            error: 'The point budget allows 100 points, 101 spent.',
            code: 'point_budget_exceeded',
          },
          { status: 422 },
        ),
    })
    const panel = await characters()
    await user.click(panel.getByRole('button', { name: 'Add Character' }))
    await user.type(panel.getByLabelText('Name'), 'Vi')
    await user.click(panel.getByRole('radio', { name: 'Roll' }))
    await user.click(panel.getByRole('button', { name: 'Save' }))

    expect(await panel.findByRole('alert')).toHaveTextContent(
      'The point budget allows 100 points, 101 spent.',
    )
    expect(panel.getByLabelText('Name')).toHaveValue('Vi')
  })
})
