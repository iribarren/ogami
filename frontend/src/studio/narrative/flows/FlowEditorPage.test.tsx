import { act, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { chooseOption, listOptions } from '@/test/chooseOption'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { draft as gameSystem, narrativeFlows } from '../../fixtures'
import { SelectionEditor } from './SelectionEditor'

// The step cards' Requirement radios sit inside this page's form, where Radix measures them.
vi.stubGlobal(
  'ResizeObserver',
  class {
    observe = vi.fn()
    unobserve = vi.fn()
    disconnect = vi.fn()
  },
)

const base = '/api/studio/game-systems/my-draft'
const put = `PUT ${base}/narrative/flows/heist`
const heist: Record<string, unknown> = narrativeFlows.flows[0]?.definition ?? {}

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

async function openEditor(flow: string, handlers: Record<string, FakeHandler> = {}, query = '') {
  const user = userEvent.setup()
  const rendered = renderAppAt(`/studio/game-systems/my-draft/flows/${flow}${query}`, {
    'GET /api/auth/me': signedInAs('GAME_MANAGER'),
    [`GET ${base}/narrative`]: json(narrativeFlows),
    ...handlers,
  })
  await screen.findByRole('form', { name: 'Flow' })
  return { user, ...rendered }
}

function nth(items: HTMLElement[], number: number) {
  const item = items[number - 1]
  if (!item) {
    throw new Error(`No item ${String(number)}.`)
  }
  return item
}
const outline = () => within(screen.getByRole('navigation', { name: 'Flow outline' }))
/** The form of the selected node. */
const content = () => within(screen.getByRole('form', { name: 'Flow' }))
type User = ReturnType<typeof userEvent.setup>
const pickSettings = (user: User) =>
  user.click(outline().getByRole('button', { name: 'Flow settings' }))
const pickPhase = (user: User, number: number) =>
  user.click(outline().getByRole('button', { name: new RegExp(`^${String(number)}\\. `) }))
const pickSelection = (user: User, number: number) =>
  user.click(nth(outline().getAllByRole('button', { name: /^Selection:/ }), number))
/** A hook of the phase `number`: the outline names them all alike. */
const hook = (name: string, number = 1) => nth(outline().getAllByRole('button', { name }), number)
const current = () => outline().getByRole('button', { current: true })
const flowKey = () => content().getByLabelText('Key')
const flowName = () => content().getByLabelText('Name')
const save = () => screen.getByRole('button', { name: 'Save' })
/** Opens the Add step menu and picks a kind, the way a person does. */
async function addStep(user: User, kind = 'prompt') {
  await user.click(content().getByRole('button', { name: 'Add step' }))
  await user.click(await screen.findByRole('menuitem', { name: kind }))
}

async function replace(user: User, field: HTMLElement, text: string) {
  await user.clear(field)
  await user.type(field, text)
}

interface Sent {
  expectedVersion: number
  item: Record<string, unknown>
}

function recorder(respond: (sent: Sent[]) => Response) {
  const sent: Sent[] = []
  const handler: FakeHandler = async (request) => {
    sent.push((await request.json()) as Sent)
    return respond(sent)
  }
  return { sent, handler }
}

const withHeist = (definition: Record<string, unknown>, version = 6) => ({
  ...narrativeFlows,
  version,
  flows: [
    { key: String(definition.key), definition, usedBy: [] },
    ...narrativeFlows.flows.slice(1),
  ],
})

const changed = {
  error: 'GameSystem "my-draft" was changed by another request. Reload it and try again.',
}

describe('the flow editor', () => {
  it('opens the flow settings with the outline of phases, selections and hooks beside them', async () => {
    await openEditor('heist')

    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Heist')
    expect(current()).toHaveAccessibleName('Flow settings')
    expect(flowKey()).toHaveValue('heist')
    expect(flowName()).toHaveValue('Heist')
    expect(screen.getByLabelText('Default flow')).toBeChecked()
    expect(screen.getByLabelText('Default view')).toHaveValue('focus')
    const oracles = within(screen.getByRole('group', { name: 'Oracle shortcuts' }))
    expect(oracles.getByRole('checkbox', { name: 'Fate' })).toBeChecked()
    expect(oracles.getByRole('checkbox', { name: 'Places' })).not.toBeChecked()
    expect(
      within(screen.getByRole('group', { name: 'Trackers' })).getByRole('checkbox', {
        name: 'Heat',
      }),
    ).not.toBeChecked()
    expect(outline().getByRole('button', { name: '1. Setup Once' })).toBeInTheDocument()
    expect(outline().getByRole('button', { name: '2. The job Loop' })).toBeInTheDocument()
    expect(outline().getAllByRole('button', { name: /^Selection:/ })).toHaveLength(2)
    expect(outline().getByRole('button', { name: 'Selection: Ambush, Quiet' })).toBeInTheDocument()
    expect(outline().getAllByRole('button', { name: 'World turn (0 steps)' })).toHaveLength(2)
    expect(screen.getByRole('link', { name: 'Back to my-draft' })).toHaveAttribute(
      'href',
      '/studio/game-systems/my-draft',
    )
    expect(screen.getByText('Default')).toBeInTheDocument()
  })

  it('edits one node at a time: a phase, its selection, a hook', async () => {
    const { user } = await openEditor('heist')

    await pickPhase(user, 1)
    expect(current()).toHaveAccessibleName('1. Setup Once')
    expect(content().getByLabelText('Key')).toHaveValue('setup')
    expect(content().getByLabelText('Mode')).toHaveValue('once')
    expect(screen.queryByLabelText('Default flow')).not.toBeInTheDocument()
    await pickPhase(user, 2)
    expect(content().getByLabelText('Mode')).toHaveValue('loop')
    await pickSelection(user, 2)
    expect(content().getByLabelText('Rule')).toHaveValue('player')
    expect(content().getByText('2. Quiet')).toBeInTheDocument()
    await user.click(hook('World turn (0 steps)', 2))
    expect(content().getByText('No steps yet.')).toBeInTheDocument()
    expect(
      within(screen.getByRole('navigation', { name: 'Breadcrumb' })).getByText('World turn'),
    ).toBeInTheDocument()
  })

  it('sends exactly the stored item, with the original key and the version it read, when nothing changed', async () => {
    const saved = recorder(() => Response.json(narrativeFlows))
    const { user } = await openEditor('heist', { [put]: saved.handler })

    await user.click(save())

    await waitFor(() => {
      expect(saved.sent).toHaveLength(1)
    })
    expect(saved.sent[0]).toEqual({ expectedVersion: 5, item: heist })
  })

  it('round-trips what it does not know, a selection and hooks the author did not touch', async () => {
    const legacy = {
      ...heist,
      legacy: { kept: true },
      phases: [
        {
          key: 'setup',
          name: 'Setup',
          act: 'Act one',
          description: 'Case the place.',
          mode: 'once',
          legacyPhase: 1,
          selection: { rule: 'oracle', table: 'places', legacySelection: 'x' },
          sceneOpening: [{ key: 'look', kind: 'prompt', title: 'Look', unknownStep: true }],
          worldTurn: [{ key: 'tick', kind: 'prompt', title: 'Tick' }],
        },
      ],
    }
    const saved = recorder(() => Response.json(withHeist(legacy)))
    const { user } = await openEditor('heist', {
      [`GET ${base}/narrative`]: json(withHeist(legacy, 5)),
      [put]: saved.handler,
    })

    expect(hook('Scene opening (1 step)')).toBeInTheDocument()
    expect(hook('World turn (1 step)')).toBeInTheDocument()
    expect(hook('Session opening (0 steps)')).toBeInTheDocument()
    await pickSelection(user, 1)
    expect(content().getByLabelText('Oracle table')).toHaveValue('places')
    await pickPhase(user, 1)
    expect(content().getByLabelText('Act')).toHaveValue('Act one')
    await user.click(save())

    await waitFor(() => {
      expect(saved.sent).toHaveLength(1)
    })
    expect(saved.sent[0]?.item).toEqual(legacy)
  })

  it('saves edited fields: a missing optional field stays missing and a false default is not sent', async () => {
    const saved = recorder(() => Response.json(narrativeFlows))
    const { user } = await openEditor('heist', { [put]: saved.handler })

    await replace(user, flowName(), 'Big heist')
    await user.type(content().getByLabelText('Description'), 'A big one.')
    await user.click(screen.getByLabelText('Default flow'))
    await chooseOption(user, 'Default view', 'Journal')
    await user.click(screen.getByRole('checkbox', { name: 'Fate' }))
    await user.click(screen.getByRole('checkbox', { name: 'Complications' }))
    await user.click(screen.getByRole('checkbox', { name: 'Heat' }))
    await pickPhase(user, 1)
    await replace(user, content().getByLabelText('Name'), 'Prep')
    await chooseOption(user, 'Mode', 'Loop', content())
    await user.type(content().getByLabelText('Act'), 'One')
    await user.click(save())

    await waitFor(() => {
      expect(saved.sent).toHaveLength(1)
    })
    const rest = Object.fromEntries(Object.entries(heist).filter(([field]) => field !== 'default'))
    const phases = heist.phases as Record<string, unknown>[]
    expect(saved.sent[0]).toEqual({
      expectedVersion: 5,
      item: {
        ...rest,
        name: 'Big heist',
        description: 'A big one.',
        defaultView: 'journal',
        oracles: ['complications'],
        trackers: ['heat'],
        phases: [{ ...phases[0], name: 'Prep', mode: 'loop', act: 'One' }, phases[1]],
      },
    })
  })

  it('opens the editor of the renamed flow and sends the next save there with the new version', async () => {
    const renamed = { ...heist, key: 'big-heist' }
    const first = recorder(() => Response.json(withHeist(renamed, 9)))
    const next = recorder(() => Response.json(withHeist(renamed, 10)))
    const { user, router } = await openEditor('heist', {
      [put]: first.handler,
      [`PUT ${base}/narrative/flows/big-heist`]: next.handler,
    })

    await replace(user, flowKey(), 'big-heist')
    await user.click(save())
    await waitFor(() => {
      expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft/flows/big-heist')
    })
    await replace(user, flowName(), 'Again')
    await user.click(save())

    await waitFor(() => {
      expect(next.sent).toHaveLength(1)
    })
    expect(first.sent[0]?.expectedVersion).toBe(5)
    expect(next.sent[0]?.expectedVersion).toBe(9)
  })
})

describe('the phase outline', () => {
  it('adds a phase with a key no phase has and no Scene Type, selects it, and refuses it empty (S10)', async () => {
    const saved = recorder(() => Response.json(narrativeFlows))
    const { user } = await openEditor('heist', { [put]: saved.handler })

    await user.click(screen.getByRole('button', { name: 'Add phase' }))

    expect(current()).toHaveAccessibleName('3. Phase 3 Once')
    expect(content().getByLabelText('Key')).toHaveValue('phase-3')
    await pickSelection(user, 3)
    expect(content().getByText('No Scene Types yet.')).toBeInTheDocument()
    expect(outline().getByRole('button', { name: 'Selection: No Scene Type' })).toBeInTheDocument()
    await pickSettings(user)
    await user.click(save())
    expect(await content().findByText('Pick at least one Scene Type.')).toBeInTheDocument()
    expect(current()).toHaveAccessibleName('Selection: No Scene Type')
    expect(saved.sent).toHaveLength(0)
    await chooseOption(user, 'Add a Scene Type', 'Ambush', content())
    await user.click(save())

    await waitFor(() => {
      expect(saved.sent).toHaveLength(1)
    })
    expect(saved.sent[0]?.item.phases).toEqual([
      ...(heist.phases as unknown[]),
      {
        key: 'phase-3',
        name: 'Phase 3',
        mode: 'once',
        selection: { rule: 'sequence', sceneTypes: ['ambush'] },
      },
    ])
  })

  it('moves a phase up or down and saves the new order', async () => {
    const saved = recorder(() => Response.json(narrativeFlows))
    const { user } = await openEditor('heist', { [put]: saved.handler })

    await pickPhase(user, 1)
    expect(screen.getByRole('button', { name: 'Move phase 1 up' })).toBeDisabled()
    await user.click(screen.getByRole('button', { name: 'Move phase 1 down' }))
    // The moved phase stays selected, at its new place.
    expect(current()).toHaveAccessibleName('2. Setup Once')
    expect(content().getByLabelText('Key')).toHaveValue('setup')
    expect(screen.getByRole('button', { name: 'Move phase 2 down' })).toBeDisabled()
    await pickPhase(user, 1)
    expect(content().getByLabelText('Key')).toHaveValue('job')
    await user.click(save())

    await waitFor(() => {
      expect(saved.sent).toHaveLength(1)
    })
    const phases = heist.phases as unknown[]
    expect(saved.sent[0]?.item.phases).toEqual([phases[1], phases[0]])
  })

  it('deletes a phase but never the last one', async () => {
    const saved = recorder(() => Response.json(narrativeFlows))
    const { user } = await openEditor('heist', { [put]: saved.handler })

    await pickPhase(user, 1)
    await user.click(screen.getByRole('button', { name: 'Delete phase 1' }))

    expect(outline().queryByRole('button', { name: /^2\. / })).not.toBeInTheDocument()
    // The next phase takes its place in the content.
    expect(current()).toHaveAccessibleName('1. The job Loop')
    expect(screen.getByRole('button', { name: 'Delete phase 1' })).toBeDisabled()
    await user.click(save())
    await waitFor(() => {
      expect(saved.sent).toHaveLength(1)
    })
    expect(saved.sent[0]?.item.phases).toEqual([(heist.phases as unknown[])[1]])
  })

  it('cannot add a phase without a Scene Type, and says so', async () => {
    await openEditor('heist', {
      [`GET ${base}/narrative`]: json({ ...narrativeFlows, sceneTypes: [] }),
    })

    const add = screen.getByRole('button', { name: 'Add phase' })
    expect(add).toBeDisabled()
    expect(add).toHaveAccessibleDescription('Add a Scene Type first.')
  })

  it('cannot add a twenty-first phase, and says so', async () => {
    const phases = Array.from({ length: 20 }, (_, at) => ({
      key: `p${String(at)}`,
      name: `P${String(at)}`,
      mode: 'once',
      selection: { rule: 'sequence', sceneTypes: ['ambush'] },
    }))
    await openEditor('heist', {
      [`GET ${base}/narrative`]: json({
        ...narrativeFlows,
        flows: [{ ...narrativeFlows.flows[0], definition: { ...heist, phases } }],
      }),
    })

    const add = screen.getByRole('button', { name: 'Add phase' })
    expect(add).toBeDisabled()
    expect(add).toHaveAccessibleDescription('A flow has at most 20 phases.')
  })

  it('sends nothing for an empty key or a key another phase has, and says so on the phase', async () => {
    const saved = recorder(() => Response.json(narrativeFlows))
    const { user } = await openEditor('heist', { [put]: saved.handler })

    await pickPhase(user, 2)
    await replace(user, content().getByLabelText('Key'), 'setup')
    await pickSettings(user)
    await user.click(save())
    expect(await content().findByText('Another phase has this key.')).toBeInTheDocument()
    expect(current()).toHaveAccessibleName('2. The job Loop')
    await user.clear(content().getByLabelText('Key'))
    await pickPhase(user, 1)
    await user.clear(content().getByLabelText('Key'))
    await pickSettings(user)
    await user.clear(flowKey())
    await user.click(save())

    // The flow's own key comes first.
    expect(await content().findByText('Enter a key.')).toBeInTheDocument()
    expect(current()).toHaveAccessibleName('Flow settings')
    await pickPhase(user, 1)
    expect(content().getByText('Enter a key.')).toBeInTheDocument()
    expect(saved.sent).toHaveLength(0)
  })
})

describe('a refused save', () => {
  it('puts a message about a flow field next to it', async () => {
    const { user } = await openEditor('heist', {
      [put]: json({ error: 'flows[0].name: must be at most 80 characters' }, 422),
    })

    await user.click(save())

    expect(await screen.findByText('must be at most 80 characters')).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent('Not saved: 1 error')
  })

  it('puts a message about a phase field on its card', async () => {
    const { user } = await openEditor('heist', {
      [put]: json({ error: 'flows[0].phases[1].key: duplicate phase key "setup"' }, 422),
    })

    await user.click(save())

    expect(await content().findByText('duplicate phase key "setup"')).toBeInTheDocument()
    expect(current()).toHaveAccessibleName('2. The job Loop')
    await pickPhase(user, 1)
    expect(content().queryByText(/duplicate/)).not.toBeInTheDocument()
  })

  it('puts a message about the selection under it, without its path', async () => {
    const { user } = await openEditor('heist', {
      [put]: json(
        { error: 'flows[0].phases[0].selection.sceneTypes[0]: unknown Scene Type "x"' },
        422,
      ),
    })

    await user.click(save())

    expect(await content().findByText('unknown Scene Type "x"')).toBeInTheDocument()
    expect(current()).toHaveAccessibleName('Selection: Ambush')
    await pickSelection(user, 2)
    expect(content().queryByText('unknown Scene Type "x"')).not.toBeInTheDocument()
  })

  it('keeps a message on the phase it is about when phases are moved or deleted before the next save', async () => {
    const { user } = await openEditor('heist', {
      [put]: json({ error: 'flows[0].phases[1].key: duplicate phase key "setup"' }, 422),
    })

    await user.click(save())
    expect(await content().findByText(/duplicate phase key/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Move phase 2 up' }))
    expect(current()).toHaveAccessibleName('1. The job Loop')
    expect(content().getByText(/duplicate phase key/)).toBeInTheDocument()
    await pickPhase(user, 2)
    expect(content().queryByText(/duplicate phase key/)).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Add phase' }))
    await pickPhase(user, 2)
    await user.click(screen.getByRole('button', { name: 'Delete phase 2' }))
    expect(current()).toHaveAccessibleName('1. The job Loop')
    expect(content().getByText(/duplicate phase key/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Delete phase 1' }))
    expect(screen.queryByText(/duplicate phase key/)).not.toBeInTheDocument()
  })

  it('keeps the phases as they were sent while the save is pending, so the message lands on the right card', async () => {
    let release: () => void = () => undefined
    const pending = new Promise<void>((resolve) => {
      release = resolve
    })
    const { user } = await openEditor('heist', {
      [put]: async () => {
        await pending
        return Response.json(
          { error: 'flows[0].phases[1].name: must be at most 80 characters' },
          { status: 422 },
        )
      },
    })

    await pickPhase(user, 1)
    await user.click(save())

    await waitFor(() => {
      expect(save()).toBeDisabled()
    })
    for (const name of ['Add phase', 'Move phase 1 down', 'Delete phase 1']) {
      expect(screen.getByRole('button', { name })).toBeDisabled()
    }
    release()
    expect(await content().findByText('must be at most 80 characters')).toBeInTheDocument()
    expect(current()).toHaveAccessibleName('2. The job Loop')
    await pickPhase(user, 1)
    expect(content().queryByText('must be at most 80 characters')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Add phase' })).toBeEnabled()
  })

  it.each([
    ['flows[0].phases[5].key: nope', 'a phase the page does not have'],
    ['flows[1].name: nope', 'another flow'],
    ['flows[0].phases: at most 20 phases', 'the phase list'],
  ])('shows %s on the page, path included (%s)', async (message) => {
    const { user } = await openEditor('heist', { [put]: json({ error: message }, 422) })

    await user.click(save())

    expect(await screen.findByText(message)).toBeInTheDocument()
  })

  it('says the flow is gone when the server cannot find it', async () => {
    const { user } = await openEditor('heist', {
      [put]: json({ error: 'The draft has no flow "heist".' }, 404),
    })

    await user.click(save())

    expect(await screen.findByText('The draft has no flow "heist".')).toBeInTheDocument()
  })
})

describe('a conflict', () => {
  const newer = { ...heist, name: 'Changed elsewhere' }

  it('takes the new version only together with the replaced draft, and saves with it', async () => {
    let reads = 0
    const saved = recorder((sent) =>
      sent.length === 1
        ? Response.json(changed, { status: 409 })
        : Response.json(withHeist(newer, 7)),
    )
    const { user } = await openEditor('heist', {
      [`GET ${base}/narrative`]: () =>
        Response.json(++reads === 1 ? narrativeFlows : withHeist(newer, 6)),
      [put]: saved.handler,
    })

    await pickPhase(user, 1)
    await replace(user, content().getByLabelText('Name'), 'Mine')
    await user.click(save())
    expect(await screen.findByText(/was changed by another request/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Reload the latest version' }))

    // The selected node stays, with the reloaded draft in it.
    await waitFor(() => {
      expect(content().getByLabelText('Name')).toHaveValue('Setup')
    })
    await pickSettings(user)
    expect(flowName()).toHaveValue('Changed elsewhere')
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    await user.click(save())

    await waitFor(() => {
      expect(saved.sent).toHaveLength(2)
    })
    expect(saved.sent[1]?.expectedVersion).toBe(6)
  })

  it.each([
    [
      'is gone',
      (version: number) => ({ ...narrativeFlows, version, flows: [] }),
      /no longer exists/,
    ],
    [
      'has a shape it cannot read',
      (version: number) => withHeist({ ...heist, phases: 'none' }, version),
      /cannot read/,
    ],
  ])('keeps the form and the old version when the flow %s', async (_, reloaded, note) => {
    let reads = 0
    const saved = recorder(() => Response.json(changed, { status: 409 }))
    const { user } = await openEditor('heist', {
      [`GET ${base}/narrative`]: () => Response.json(++reads === 1 ? narrativeFlows : reloaded(6)),
      [put]: saved.handler,
    })

    await pickPhase(user, 1)
    await replace(user, content().getByLabelText('Name'), 'Mine')
    await user.click(save())
    await user.click(await screen.findByRole('button', { name: 'Reload the latest version' }))

    expect(await screen.findByText(note)).toBeInTheDocument()
    expect(content().getByLabelText('Name')).toHaveValue('Mine')
    await user.click(save())
    await waitFor(() => {
      expect(saved.sent).toHaveLength(2)
    })
    expect(saved.sent[1]?.expectedVersion).toBe(5)
  })

  it('says when the latest version could not be loaded, and keeps the form', async () => {
    let reads = 0
    const { user } = await openEditor('heist', {
      [`GET ${base}/narrative`]: () =>
        ++reads === 1
          ? Response.json(narrativeFlows)
          : Response.json({ error: 'Gone' }, { status: 404 }),
      [put]: json(changed, 409),
    })

    await pickPhase(user, 1)
    await replace(user, content().getByLabelText('Name'), 'Mine')
    await user.click(save())
    await user.click(await screen.findByRole('button', { name: 'Reload the latest version' }))

    expect(await screen.findByText(/Couldn.t reload the latest version/)).toBeInTheDocument()
    expect(content().getByLabelText('Name')).toHaveValue('Mine')
  })
})

describe('a flow that cannot be opened', () => {
  const open = (flow: string, narrative: FakeHandler = json(narrativeFlows)) =>
    renderAppAt(`/studio/game-systems/my-draft/flows/${flow}`, {
      'GET /api/auth/me': signedInAs('GAME_MANAGER'),
      [`GET ${base}/narrative`]: narrative,
    })

  it('says so, with a way back, when the draft has no such flow', async () => {
    open('nope')

    expect(await screen.findByRole('alert')).toHaveTextContent('The draft has no flow “nope”.')
    expect(screen.getByRole('link', { name: 'Back to my-draft' })).toBeInTheDocument()
  })

  it('shows a flow of a shape the editor cannot read read-only, with a reason and no save', async () => {
    open('odd-flow')

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'has a shape this editor does not know',
    )
    expect(screen.queryByRole('form', { name: 'Flow' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument()
  })

  it('says the GameSystem is not found when the narrative is a 404', async () => {
    open('heist', json({ error: 'No GameSystem' }, 404))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'This GameSystem does not exist, or it is not one of yours.',
    )
  })
})

describe('the selection of a phase', () => {
  const sentPhases = (saved: { sent: Sent[] }) =>
    (saved.sent[0]?.item.phases as Record<string, unknown>[]).map(({ selection }) => selection)

  async function saveAfter(edit: (user: User) => Promise<void>) {
    const saved = recorder(() => Response.json(narrativeFlows))
    const { user } = await openEditor('heist', { [put]: saved.handler })
    await pickSelection(user, 1)
    await edit(user)
    await user.click(save())
    return saved
  }

  it('adds, moves and removes Scene Types of a sequence, which may name one twice', async () => {
    const saved = await saveAfter(async (user) => {
      await chooseOption(user, 'Add a Scene Type', 'Quiet', content())
      await chooseOption(user, 'Add a Scene Type', 'Ambush', content())
      await user.click(content().getByRole('button', { name: 'Move Scene Type 2 up' }))
      await user.click(content().getByRole('button', { name: 'Remove Scene Type 3' }))
    })

    await waitFor(() => {
      expect(saved.sent).toHaveLength(1)
    })
    expect(sentPhases(saved)[0]).toEqual({ rule: 'sequence', sceneTypes: ['quiet', 'ambush'] })
  })

  it('offers a player list only the Scene Types it does not name yet', async () => {
    const { user } = await openEditor('heist')

    await pickSelection(user, 2)
    const add = content().getByLabelText('Add a Scene Type')
    expect((await listOptions(user, add)).map((option) => option.label)).toEqual([
      'Pick a Scene Type',
      'Odd',
    ])
  })

  it('keeps the Scene Types when the rule goes from sequence to player', async () => {
    const saved = await saveAfter(async (user) => {
      await chooseOption(user, 'Rule', 'The player picks', content())
    })

    await waitFor(() => {
      expect(saved.sent).toHaveLength(1)
    })
    expect(sentPhases(saved)[0]).toEqual({ rule: 'player', sceneTypes: ['ambush'] })
  })

  it('sends an oracle selection once a table is picked, and nothing before', async () => {
    const saved = recorder(() => Response.json(narrativeFlows))
    const { user } = await openEditor('heist', { [put]: saved.handler })

    await pickSelection(user, 1)
    await chooseOption(user, 'Rule', 'An oracle table picks', content())
    expect(content().queryByLabelText('Add a Scene Type')).not.toBeInTheDocument()
    await user.click(save())
    expect(await content().findByText('Pick an oracle table.')).toBeInTheDocument()
    expect(saved.sent).toHaveLength(0)
    await chooseOption(user, 'Oracle table', 'Places', content())
    await user.click(save())

    await waitFor(() => {
      expect(saved.sent).toHaveLength(1)
    })
    expect(sentPhases(saved)[0]).toEqual({ rule: 'oracle', table: 'places' })
    expect(screen.queryByText('Pick an oracle table.')).not.toBeInTheDocument()
  })

  it('starts the Scene Types empty when the rule leaves an oracle, and sends nothing without one', async () => {
    const saved = recorder(() => Response.json(narrativeFlows))
    const oracle = {
      ...heist,
      phases: [
        { ...(heist.phases as object[])[0], selection: { rule: 'oracle', table: 'places' } },
      ],
    }
    const { user } = await openEditor('heist', {
      [`GET ${base}/narrative`]: json(withHeist(oracle, 5)),
      [put]: saved.handler,
    })

    await pickSelection(user, 1)
    await chooseOption(user, 'Rule', 'Plays in order', content())
    expect(content().getByText('No Scene Types yet.')).toBeInTheDocument()
    await user.click(save())

    expect(await content().findByText('Pick at least one Scene Type.')).toBeInTheDocument()
    expect(saved.sent).toHaveLength(0)
  })

  it('sends nothing when a player list would name a Scene Type twice', async () => {
    const saved = recorder(() => Response.json(narrativeFlows))
    const { user } = await openEditor('heist', { [put]: saved.handler })

    await pickSelection(user, 1)
    await chooseOption(user, 'Add a Scene Type', 'Ambush', content())
    await chooseOption(user, 'Rule', 'The player picks', content())
    await user.click(save())

    expect(
      await content().findByText('A player selection lists each Scene Type once.'),
    ).toBeInTheDocument()
    expect(saved.sent).toHaveLength(0)
  })

  it("puts the publisher's message about a table under the table picker", async () => {
    const oracle = {
      ...heist,
      phases: [
        { ...(heist.phases as object[])[0], selection: { rule: 'oracle', table: 'places' } },
      ],
    }
    const { user } = await openEditor('heist', {
      [`GET ${base}/narrative`]: json(withHeist(oracle, 5)),
      [put]: json(
        { error: 'flows[0].phases[0].selection.table: unknown oracle table "places"' },
        422,
      ),
    })

    await user.click(save())

    expect(await content().findByText('unknown oracle table "places"')).toBeInTheDocument()
  })
})

describe('the hooks of a phase', () => {
  const stored = (hooks: Record<string, unknown>) => {
    const phases = heist.phases as Record<string, unknown>[]
    return withHeist({ ...heist, phases: [{ ...phases[0], ...hooks }, phases[1]] }, 5)
  }

  it('opens a hook to edit its steps and sends them in that hook only', async () => {
    const saved = recorder(() => Response.json(narrativeFlows))
    const { user } = await openEditor('heist', { [put]: saved.handler })

    await user.click(hook('World turn (0 steps)'))
    await addStep(user)
    await replace(user, content().getByLabelText('Title'), 'Tick')
    expect(hook('World turn (1 step)')).toBeInTheDocument()
    await user.click(save())

    await waitFor(() => {
      expect(saved.sent).toHaveLength(1)
    })
    const sent = (saved.sent[0]?.item.phases as Record<string, unknown>[])[0]
    expect(sent?.worldTurn).toMatchObject([{ kind: 'prompt', title: 'Tick' }])
    expect(Object.keys(sent ?? {})).not.toContain('sessionOpening')
  })

  it('edits a stored hook step and keeps the step fields it does not know', async () => {
    const step = { key: 'look', kind: 'prompt', title: 'Look', unknownStep: true }
    const saved = recorder(() => Response.json(narrativeFlows))
    const { user } = await openEditor('heist', {
      [`GET ${base}/narrative`]: json(stored({ sceneOpening: [step] })),
      [put]: saved.handler,
    })

    await user.click(hook('Scene opening (1 step)'))
    await replace(user, content().getByLabelText('Title'), 'Look closer')
    await user.click(save())

    await waitFor(() => {
      expect(saved.sent).toHaveLength(1)
    })
    const sent = (saved.sent[0]?.item.phases as Record<string, unknown>[])[0]
    expect(sent?.sceneOpening).toEqual([{ ...step, title: 'Look closer' }])
  })

  it('does not send a hook the author emptied, and keeps the others', async () => {
    const step = { key: 'look', kind: 'prompt', title: 'Look' }
    const saved = recorder(() => Response.json(narrativeFlows))
    const { user } = await openEditor('heist', {
      [`GET ${base}/narrative`]: json(stored({ sceneOpening: [step], worldTurn: [step] })),
      [put]: saved.handler,
    })

    await user.click(hook('World turn (1 step)'))
    await user.click(content().getByRole('button', { name: 'Delete step 1' }))
    expect(hook('World turn (0 steps)')).toBeInTheDocument()
    await user.click(save())

    await waitFor(() => {
      expect(saved.sent).toHaveLength(1)
    })
    const sent = (saved.sent[0]?.item.phases as Record<string, unknown>[])[0]
    expect(sent).toMatchObject({ sceneOpening: [step] })
    expect(Object.keys(sent ?? {})).not.toContain('worldTurn')
  })

  it('sends a stored empty hook back as it was', async () => {
    const saved = recorder(() => Response.json(narrativeFlows))
    const { user } = await openEditor('heist', {
      [`GET ${base}/narrative`]: json(stored({ sessionOpening: [] })),
      [put]: saved.handler,
    })

    await user.click(save())

    await waitFor(() => {
      expect(saved.sent).toHaveLength(1)
    })
    expect((saved.sent[0]?.item.phases as Record<string, unknown>[])[0]).toHaveProperty(
      'sessionOpening',
      [],
    )
  })

  it('shows a value that is not a whole number on its step and sends nothing', async () => {
    const saved = recorder(() => Response.json(narrativeFlows))
    const { user } = await openEditor('heist', { [put]: saved.handler })

    await user.click(hook('Phase closing (0 steps)'))
    await addStep(user)
    await user.click(content().getByRole('button', { name: 'Add effect' }))
    await replace(user, content().getByLabelText('Value'), '1.5')
    await user.click(save())

    expect(await content().findByText('Use a whole number.')).toBeInTheDocument()
    expect(saved.sent).toHaveLength(0)

    await replace(user, content().getByLabelText('Value'), '2')
    await user.click(save())

    await waitFor(() => {
      expect(saved.sent).toHaveLength(1)
    })
    expect(screen.queryByText('Use a whole number.')).not.toBeInTheDocument()
  })

  it('opens the hook a refusal is about and puts the message on its step, on the right phase', async () => {
    const step = { key: 'look', kind: 'prompt', title: 'Look' }
    const phases = heist.phases as Record<string, unknown>[]
    const both = withHeist(
      {
        ...heist,
        phases: [
          { ...phases[0], worldTurn: [step] },
          { ...phases[1], worldTurn: [step] },
        ],
      },
      5,
    )
    const { user } = await openEditor('heist', {
      [`GET ${base}/narrative`]: json(both),
      [put]: json({ error: 'flows[0].phases[1].worldTurn[0].title: must not be blank' }, 422),
    })

    await user.click(save())

    // The refusal selects the step it is about, and the outline flags it.
    expect(
      within(await content().findByRole('group', { name: 'Step 1' })).getByText(
        'must not be blank',
      ),
    ).toBeInTheDocument()
    expect(current()).toHaveTextContent('Look')
    expect(outline().getAllByText('Error')).toHaveLength(1)
    // The message follows its phase when the phases are moved.
    await pickPhase(user, 2)
    await user.click(screen.getByRole('button', { name: 'Move phase 2 up' }))
    await user.click(hook('World turn (1 step)', 1))
    expect(
      within(content().getByRole('group', { name: 'Step 1' })).getByText('must not be blank'),
    ).toBeInTheDocument()
    await user.click(hook('World turn (1 step)', 2))
    expect(content().queryByText('must not be blank')).not.toBeInTheDocument()
  })

  it('puts a message about a hook list itself above that list, path included', async () => {
    const { user } = await openEditor('heist', {
      [put]: json({ error: 'flows[0].phases[0].sceneClosing: at most 50 steps' }, 422),
    })

    await user.click(save())

    expect(
      await content().findByText('flows[0].phases[0].sceneClosing: at most 50 steps'),
    ).toBeInTheDocument()
    expect(current()).toHaveAccessibleName('Scene closing (0 steps)')
  })

  it('disables the hook lists while a save is pending', async () => {
    let release: () => void = () => undefined
    const pending = new Promise<void>((resolve) => {
      release = resolve
    })
    const { user } = await openEditor('heist', {
      [put]: async () => {
        await pending
        return Response.json(narrativeFlows)
      },
    })

    await user.click(hook('World turn (0 steps)'))
    await user.click(save())

    await waitFor(() => {
      expect(content().getByRole('button', { name: 'Add step' })).toBeDisabled()
    })
    release()
    await waitFor(() => {
      expect(content().getByRole('button', { name: 'Add step' })).toBeEnabled()
    })
  })
})

describe('the outline', () => {
  it('puts the selected node in the URL, so back returns to the node before', async () => {
    const { user, router } = await openEditor('heist')

    await pickPhase(user, 2)
    await pickSelection(user, 1)
    expect(router.state.location.search).toEqual({ node: 'phases.0.selection' })
    act(() => {
      router.history.back()
    })

    await waitFor(() => {
      expect(current()).toHaveAccessibleName('2. The job Loop')
    })
    expect(content().getByLabelText('Mode')).toHaveValue('loop')
  })

  it('opens the node the URL names, as a reload does', async () => {
    await openEditor('heist', {}, '?node=phases.1.selection')

    expect(current()).toHaveAccessibleName('Selection: Ambush, Quiet')
    expect(content().getByLabelText('Rule')).toHaveValue('player')
  })

  it.each([
    ['garbage'],
    ['phases.9'],
    ['phases.1.hooks.nothing'],
    ['phases.9.hooks.worldTurn.0'],
    ['phases.01'],
  ])('opens the flow settings for the node %j, which names nothing', async (node) => {
    await openEditor('heist', {}, `?node=${node}`)

    expect(current()).toHaveAccessibleName('Flow settings')
    expect(flowKey()).toHaveValue('heist')
  })

  it('drops a node value that is no path from the URL', async () => {
    const { router } = await openEditor('heist', {}, '?node=garbage')

    expect(router.state.location.search).toEqual({})
  })

  it('opens the hook for a step the hook does not have', async () => {
    await openEditor('heist', {}, '?node=phases.0.hooks.worldTurn.4')

    expect(current()).toHaveAccessibleName('World turn (0 steps)')
  })

  it('shows the hook list for a step, with that step marked and in view', async () => {
    const step = { key: 'look', kind: 'prompt', title: 'Look' }
    const phases = heist.phases as Record<string, unknown>[]
    const scroll = vi.spyOn(Element.prototype, 'scrollIntoView')
    await openEditor(
      'heist',
      {
        [`GET ${base}/narrative`]: json(
          withHeist(
            {
              ...heist,
              phases: [{ ...phases[0], sceneOpening: [step, { ...step, key: 'b' }] }, phases[1]],
            },
            5,
          ),
        ),
      },
      '?node=phases.0.hooks.sceneOpening.1',
    )

    expect(current()).toHaveAccessibleName('Look')
    expect(content().getAllByRole('group', { name: /^Step / })).toHaveLength(2)
    expect(content().getByRole('group', { name: 'Step 2' })).toHaveAttribute('aria-current', 'true')
    expect(content().getByRole('group', { name: 'Step 1' })).not.toHaveAttribute('aria-current')
    expect(scroll).toHaveBeenCalled()
    scroll.mockRestore()
  })

  it('shows a legacy phase with no hooks and an empty selection', async () => {
    const legacy = {
      ...heist,
      phases: [
        { key: 'old', name: '', mode: 'once', selection: { rule: 'sequence', sceneTypes: [] } },
      ],
    }
    const { user } = await openEditor('heist', {
      [`GET ${base}/narrative`]: json(withHeist(legacy, 5)),
    })

    expect(outline().getByRole('button', { name: '1. old Once' })).toBeInTheDocument()
    expect(hook('Session opening (0 steps)')).toBeInTheDocument()
    await pickSelection(user, 1)
    expect(content().getByText('No Scene Types yet.')).toBeInTheDocument()
    await pickPhase(user, 1)
    expect(content().getByLabelText('Key')).toHaveValue('old')
  })
})

describe('the header of the flow editor page', () => {
  const system = { [`GET ${base.replace('/narrative', '')}`]: json(gameSystem) }
  // Split once: a message may contain `: ` itself.
  const errorOf = (error: string) => {
    const at = error.indexOf(': ')
    return { path: error.slice(0, at), message: error.slice(at + 2) }
  }
  const validation = (error: string | null, warnings: string[]) =>
    json({
      valid: error === null,
      errors: error === null ? [] : [errorOf(error)],
      warnings,
    })
  const check = `GET ${base}/validation`

  it('names the GameSystem, and counts the issues of this flow only', async () => {
    await openEditor('heist', {
      ...system,
      [check]: validation('flows[0].name: nope', ['flows[0].phases[1]: odd', 'flows[1]: other']),
    })

    expect(await screen.findByText('2 issues')).toBeInTheDocument()
    expect(screen.getByText('My draft · Flow')).toBeInTheDocument()
  })

  it('says "1 issue" for one', async () => {
    await openEditor('heist', { ...system, [check]: validation(null, ['flows[0]: odd']) })

    expect(await screen.findByText('1 issue')).toBeInTheDocument()
  })

  it('shows no badge when the issues are about other flows', async () => {
    await openEditor('heist', { ...system, [check]: validation(null, ['flows[1]: other']) })

    await waitFor(() => {
      expect(screen.getByText('My draft · Flow')).toBeInTheDocument()
    })
    expect(screen.queryByText(/issues?$/)).not.toBeInTheDocument()
  })

  it('falls back to the key and no badge when the GameSystem or the validation fail', async () => {
    await openEditor('heist', {
      [`GET ${base.replace('/narrative', '')}`]: json({ error: 'Down' }, 500),
      [check]: json({ error: 'Down' }, 500),
    })

    await waitFor(() => {
      expect(screen.getByText('my-draft · Flow')).toBeInTheDocument()
    })
    expect(screen.queryByText(/issues?$/)).not.toBeInTheDocument()
  })

  it('shows no badge for a flow that has gone from the draft, whatever the validation says', async () => {
    let reads = 0
    const { user } = await openEditor('heist', {
      ...system,
      [`GET ${base}/narrative`]: () =>
        Response.json(++reads === 1 ? narrativeFlows : { ...narrativeFlows, flows: [] }),
      [check]: validation('flows[0].name: nope', []),
      [put]: json(changed, 409),
    })
    expect(await screen.findByText('1 issue')).toBeInTheDocument()

    await pickPhase(user, 1)
    await replace(user, content().getByLabelText('Name'), 'Mine')
    await user.click(save())
    await user.click(await screen.findByRole('button', { name: 'Reload the latest version' }))

    expect(await screen.findByText(/no longer exists/)).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Heist')
    expect(screen.queryByText(/issues?$/)).not.toBeInTheDocument()
  })
})

describe('the save bar', () => {
  const rename = async (user: User) => {
    await replace(user, flowName(), 'Mine')
  }

  it('shows nothing, then unsaved changes, then when it was saved, and unsaved again after an edit', async () => {
    const { user } = await openEditor('heist', {
      [put]: () => Response.json(withHeist({ ...heist, name: 'Mine' }, 6)),
    })
    expect(screen.queryByText(/Unsaved changes|Saved at/)).not.toBeInTheDocument()

    await rename(user)
    expect(screen.getByText('Unsaved changes')).toBeInTheDocument()
    await user.click(save())
    expect(await screen.findByRole('status')).toHaveTextContent(/^Saved at/)

    await replace(user, flowName(), 'Mine again')
    expect(screen.getByText('Unsaved changes')).toBeInTheDocument()
    await replace(user, flowName(), 'Mine')
    expect(screen.getByRole('status')).toHaveTextContent(/^Saved at/)
  })

  it('says how many errors stopped the save, and goes to the first one and focuses its field', async () => {
    const { user } = await openEditor('heist', {
      [put]: json({ error: 'flows[0].phases[1].key: duplicate phase key "setup"' }, 422),
    })
    await user.click(save())
    expect(await screen.findByRole('alert')).toHaveTextContent('Not saved: 1 error')
    await pickSettings(user)
    expect(current()).toHaveAccessibleName('Flow settings')

    await user.click(screen.getByRole('button', { name: 'Go to the first' }))

    await waitFor(() => {
      expect(current()).toHaveAccessibleName(/^2\. The job/)
    })
    await waitFor(() => {
      expect(content().getByLabelText('Key')).toHaveFocus()
    })
  })

  it('focuses the field once per request, and again on a new one', async () => {
    const { user } = await openEditor('heist', {
      [put]: json({ error: 'flows[0].phases[1].key: duplicate phase key "setup"' }, 422),
    })
    await user.click(save())
    await screen.findByRole('alert')
    await pickSettings(user)
    await user.click(screen.getByRole('button', { name: 'Go to the first' }))
    await waitFor(() => {
      expect(content().getByLabelText('Key')).toHaveFocus()
    })

    // An unrelated change keeps the focus where the person put it.
    await user.click(content().getByLabelText('Name'))
    await user.type(content().getByLabelText('Name'), 'x')
    expect(content().getByLabelText('Name')).toHaveFocus()
    // Coming back to the node later does not take the focus either.
    await pickSettings(user)
    await pickPhase(user, 2)
    expect(content().getByLabelText('Key')).not.toHaveFocus()

    await user.click(screen.getByRole('button', { name: 'Go to the first' }))
    await waitFor(() => {
      expect(content().getByLabelText('Key')).toHaveFocus()
    })
  })

  it('opens the step card a refusal is about, even when the person closed it', async () => {
    const phases = heist.phases as Record<string, unknown>[]
    const choice = {
      key: 'ask',
      kind: 'choice',
      title: 'Ask',
      skip: 'a',
      options: [
        { key: 'a', label: 'A' },
        { key: 'b', label: 'B' },
      ],
    }
    const { user } = await openEditor(
      'heist',
      {
        [`GET ${base}/narrative`]: json(
          withHeist({ ...heist, phases: [{ ...phases[0], worldTurn: [choice] }, phases[1]] }),
        ),
        [put]: json({ error: 'flows[0].phases[0].worldTurn[0].skip: must be an option key' }, 422),
      },
      '?node=phases.0.hooks.worldTurn',
    )
    const step = await content().findByRole('group', { name: 'Step 1' })
    await user.click(within(step).getByRole('button', { name: /^Ask/ }))
    expect(within(step).queryByLabelText('Title')).not.toBeInTheDocument()

    await user.click(save())

    const skip = await within(await content().findByRole('group', { name: 'Step 1' })).findByRole(
      'combobox',
      { name: 'Skip' },
    )
    expect(skip).toHaveAttribute('aria-invalid', 'true')
  })

  it('opens the current step card when a refused save has an error in it, and Go to the first focuses it', async () => {
    const phases = heist.phases as Record<string, unknown>[]
    const choice = {
      key: 'ask',
      kind: 'choice',
      title: 'Ask',
      skip: 'a',
      options: [
        { key: 'a', label: 'A' },
        { key: 'b', label: 'B' },
      ],
    }
    const { user } = await openEditor(
      'heist',
      {
        [`GET ${base}/narrative`]: json(
          withHeist({ ...heist, phases: [{ ...phases[0], worldTurn: [choice] }, phases[1]] }),
        ),
        [put]: json({ error: 'flows[0].phases[0].worldTurn[0].skip: must be an option key' }, 422),
      },
      '?node=phases.0.hooks.worldTurn.0',
    )
    const step = await content().findByRole('group', { name: 'Step 1' })
    await user.click(within(step).getByRole('button', { name: /^Ask/ }))
    expect(within(step).queryByLabelText('Title')).not.toBeInTheDocument()

    await user.click(save())

    const skip = await within(await content().findByRole('group', { name: 'Step 1' })).findByRole(
      'combobox',
      { name: 'Skip' },
    )
    await user.click(screen.getByRole('button', { name: 'Go to the first' }))
    await waitFor(() => {
      expect(skip).toHaveFocus()
    })
  })

  it('offers the latest version on a conflict, next to Save', async () => {
    const { user } = await openEditor('heist', { [put]: json(changed, 409) })

    await user.click(save())

    expect(await screen.findByRole('button', { name: 'Reload the latest version' })).toBeVisible()
    expect(screen.getByRole('alert')).toHaveTextContent('Not saved: 1 error')
  })

  it.each([
    [
      'a flow field',
      'flows[0].name: nope',
      () => outline().getByRole('button', { name: 'Flow settings' }),
    ],
    [
      'a phase field',
      'flows[0].phases[0].key: nope',
      () => nth(outline().getAllByRole('button', { name: /^\d\. / }), 1),
    ],
    [
      'a selection',
      'flows[0].phases[1].selection.sceneTypes[0]: nope',
      () => nth(outline().getAllByRole('button', { name: /^Selection:/ }), 2),
    ],
    [
      'a hook list',
      'flows[0].phases[0].sceneClosing: nope',
      () => hook('Scene closing (0 steps)', 1),
    ],
  ])('marks the outline item of %s', async (_, message, item) => {
    const { user } = await openEditor('heist', { [put]: json({ error: message }, 422) })

    await user.click(save())

    await waitFor(() => {
      expect(item()).toHaveAttribute('aria-description', 'Has an error')
    })
    expect(outline().getAllByText('Error')).toHaveLength(1)
    // The pill is not part of the name.
    expect(item()).not.toHaveAccessibleName(/Error/)
  })

  it('puts a refusal on its field: the default view', async () => {
    const { user } = await openEditor('heist', {
      [put]: json({ error: 'flows[0].defaultView: must be focus or journal' }, 422),
    })
    await user.click(save())
    const view = await screen.findByRole('combobox', { name: 'Default view' })
    expect(view).toHaveAttribute('aria-invalid', 'true')
    expect(view).toHaveAccessibleDescription('must be focus or journal')
  })

  it('puts a refusal of the Skip label on the Skip field', async () => {
    const phases = heist.phases as Record<string, unknown>[]
    const choice = {
      key: 'ask',
      kind: 'choice',
      title: 'Ask',
      skip: 'a',
      options: [
        { key: 'a', label: 'A' },
        { key: 'b', label: 'B' },
      ],
    }
    const { user } = await openEditor('heist', {
      [`GET ${base}/narrative`]: json(
        withHeist({ ...heist, phases: [{ ...phases[0], worldTurn: [choice] }, phases[1]] }),
      ),
      [put]: json({ error: 'flows[0].phases[0].worldTurn[0].skip: must be an option key' }, 422),
    })

    await user.click(save())

    const skip = await within(await content().findByRole('group', { name: 'Step 1' })).findByRole(
      'combobox',
      { name: 'Skip' },
    )
    expect(skip).toHaveAttribute('aria-invalid', 'true')
    expect(skip).toHaveAccessibleDescription('must be an option key')
  })
})

describe('the {answer} hint', () => {
  const hint = /^Effects can use \{answer\}/
  const withStep = (step: Record<string, unknown>) => {
    const phases = heist.phases as Record<string, unknown>[]
    return withHeist({ ...heist, phases: [{ ...phases[0], worldTurn: [step] }, phases[1]] })
  }
  const open = (step: Record<string, unknown>) =>
    openEditor(
      'heist',
      { [`GET ${base}/narrative`]: json(withStep(step)) },
      '?node=phases.0.hooks.worldTurn.0',
    )

  it('sits under the prompt and the text of the effects of a prompt step, linked to them', async () => {
    await open({
      key: 'look',
      kind: 'prompt',
      title: 'Look',
      effects: [{ kind: 'sceneTitle', title: 'T' }],
    })

    const texts = await screen.findAllByText(hint)
    expect(texts).toHaveLength(2)
    const prompt = content().getByLabelText('Prompt')
    expect(prompt).toHaveAccessibleDescription(texts[0]?.textContent ?? '')
  })

  it('is not shown for a step that has no answer', async () => {
    await open({
      key: 'who',
      kind: 'character',
      title: 'Who',
      effects: [{ kind: 'sceneTitle', title: 'T' }],
    })

    await screen.findByLabelText('Prompt')
    expect(screen.queryByText(hint)).not.toBeInTheDocument()
  })
})

describe('enabling on the flow', () => {
  const tracked = {
    key: 'look',
    kind: 'prompt',
    title: 'Look',
    effects: [{ kind: 'tracker', tracker: 'heat', op: 'add', value: 1 }],
  }
  const narrativeWith = (ambushOracles: string[] = []) => {
    const phases = heist.phases as Record<string, unknown>[]
    const flows = withHeist({
      ...heist,
      phases: [{ ...phases[0], worldTurn: [tracked] }, phases[1]],
    })
    return {
      ...flows,
      sceneTypes: flows.sceneTypes.map((item) =>
        item.key === 'ambush'
          ? { ...item, definition: { ...item.definition, oracles: ambushOracles } }
          : item,
      ),
    }
  }
  const open = (query: string, handlers: Record<string, FakeHandler> = {}, oracles?: string[]) =>
    openEditor(
      'heist',
      { [`GET ${base}/narrative`]: json(narrativeWith(oracles)), ...handlers },
      query,
    )
  const enable = (user: User) => user.click(screen.getByRole('button', { name: 'Enable on flow' }))

  it('warns on the step that uses a tracker the flow lacks, and adds it when asked, unsaved', async () => {
    const saved = recorder(() => Response.json(narrativeFlows))
    const { user } = await open('?node=phases.0.hooks.worldTurn.0', { [put]: saved.handler })
    expect(await content().findByText('Tracker Heat')).toBeInTheDocument()

    await enable(user)

    expect(screen.queryByRole('button', { name: 'Enable on flow' })).not.toBeInTheDocument()
    expect(screen.getByText('Unsaved changes')).toBeInTheDocument()
    expect(saved.sent).toHaveLength(0)
    await pickSettings(user)
    expect(
      within(screen.getByRole('group', { name: 'Trackers' })).getByRole('checkbox', {
        name: 'Heat',
      }),
    ).toBeChecked()
    await user.click(save())
    await waitFor(() => {
      expect(saved.sent[0]?.item.trackers).toEqual(['heat'])
    })
  })

  it('warns on the selection about the shortcuts of its Scene Types, and keeps the others', async () => {
    const saved = recorder(() => Response.json(narrativeFlows))
    const { user } = await open('?node=phases.0.selection', { [put]: saved.handler }, ['places'])
    expect(await content().findByText('Oracle shortcut Places')).toBeInTheDocument()

    await enable(user)
    await user.click(save())

    await waitFor(() => {
      expect(saved.sent[0]?.item.oracles).toEqual(['fate', 'places'])
    })
  })

  it('lists everything the flow lacks in the flow settings and enables it in one go', async () => {
    const { user } = await open('', {}, ['places'])
    expect(await content().findByText('Tracker Heat')).toBeInTheDocument()
    expect(content().getByText('Oracle shortcut Places')).toBeInTheDocument()

    await enable(user)

    expect(screen.queryByRole('button', { name: 'Enable on flow' })).not.toBeInTheDocument()
    expect(
      within(screen.getByRole('group', { name: 'Oracle shortcuts' })).getByRole('checkbox', {
        name: 'Places',
      }),
    ).toBeChecked()
  })

  it('says nothing when the flow enables what its steps use', async () => {
    const phases = heist.phases as Record<string, unknown>[]
    await openEditor(
      'heist',
      {
        [`GET ${base}/narrative`]: json(
          withHeist({
            ...heist,
            trackers: ['heat'],
            phases: [{ ...phases[0], worldTurn: [tracked] }, phases[1]],
          }),
        ),
      },
      '?node=phases.0.hooks.worldTurn.0',
    )

    await waitFor(() => {
      expect(current()).toHaveTextContent('Look')
    })
    expect(screen.queryByRole('button', { name: 'Enable on flow' })).not.toBeInTheDocument()
  })
})

describe('the messages about an oracle selection', () => {
  it('puts the first on the table field and lists the rest', () => {
    render(
      <SelectionEditor
        selection={{ rule: 'oracle', table: 'places', extra: {} }}
        sceneTypes={[]}
        tables={[{ key: 'places', name: 'Places' }]}
        messages={['unknown oracle table', 'Another message.']}
        onChange={() => undefined}
      />,
    )

    expect(screen.getByRole('combobox', { name: 'Oracle table' })).toHaveAccessibleDescription(
      'unknown oracle table',
    )
    expect(screen.getByText('Another message.')).toBeInTheDocument()
    expect(screen.getAllByText('unknown oracle table')).toHaveLength(1)
  })
})
