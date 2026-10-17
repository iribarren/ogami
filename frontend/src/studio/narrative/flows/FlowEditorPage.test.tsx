import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { narrativeFlows } from '../../fixtures'

const base = '/api/studio/game-systems/my-draft'
const put = `PUT ${base}/narrative/flows/heist`
const heist: Record<string, unknown> = narrativeFlows.flows[0]?.definition ?? {}

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

async function openEditor(flow: string, handlers: Record<string, FakeHandler> = {}) {
  const user = userEvent.setup()
  const rendered = renderAppAt(`/studio/game-systems/my-draft/flows/${flow}`, {
    'GET /api/auth/me': signedInAs('GAME_MANAGER'),
    [`GET ${base}/narrative`]: json(narrativeFlows),
    ...handlers,
  })
  await screen.findByRole('form', { name: 'Flow' })
  return { user, ...rendered }
}

const phase = (number: number) =>
  within(screen.getByRole('group', { name: `Phase ${String(number)}` }))
/** The flow's own key: every phase card has a Key of its own too. */
function first(label: string) {
  const [field] = screen.getAllByLabelText(label)
  if (!field) {
    throw new Error(`No ${label} field.`)
  }
  return field
}
const flowKey = () => first('Key')
const flowName = () => first('Name')
const save = () => screen.getByRole('button', { name: 'Save' })

async function replace(user: ReturnType<typeof userEvent.setup>, field: HTMLElement, text: string) {
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
  it('shows the fields, the pickers and one card per phase, with its selection and hooks summarised', async () => {
    await openEditor('heist')

    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Heist')
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
    expect(phase(1).getByLabelText('Key')).toHaveValue('setup')
    expect(phase(1).getByLabelText('Mode')).toHaveValue('once')
    expect(phase(1).getByText('Plays in order: ambush.')).toBeInTheDocument()
    expect(phase(2).getByLabelText('Mode')).toHaveValue('loop')
    expect(phase(2).getByText('The player picks from: ambush, quiet.')).toBeInTheDocument()
    expect(phase(2).getByText('No hooks.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Back to my-draft' })).toHaveAttribute(
      'href',
      '/studio/game-systems/my-draft',
    )
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

  it('round-trips what it does not know, and the selection and hooks it only summarises', async () => {
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

    expect(phase(1).getByText('Picks scenes from the oracle table places.')).toBeInTheDocument()
    expect(
      phase(1).getByText('Hooks: scene opening 1 step, world turn 1 step.'),
    ).toBeInTheDocument()
    expect(phase(1).getByLabelText('Act')).toHaveValue('Act one')
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
    await user.type(first('Description'), 'A big one.')
    await user.click(screen.getByLabelText('Default flow'))
    await user.selectOptions(screen.getByLabelText('Default view'), 'Journal')
    await user.click(screen.getByRole('checkbox', { name: 'Fate' }))
    await user.click(screen.getByRole('checkbox', { name: 'Complications' }))
    await user.click(screen.getByRole('checkbox', { name: 'Heat' }))
    await replace(user, phase(1).getByLabelText('Name'), 'Prep')
    await user.selectOptions(phase(1).getByLabelText('Mode'), 'Loop')
    await user.type(phase(1).getByLabelText('Act'), 'One')
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
  it('adds a phase with a key no phase has, starting at the first Scene Type, and saves it last', async () => {
    const saved = recorder(() => Response.json(narrativeFlows))
    const { user } = await openEditor('heist', { [put]: saved.handler })

    await user.click(screen.getByRole('button', { name: 'Add phase' }))

    expect(phase(3).getByLabelText('Key')).toHaveValue('phase-3')
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

    expect(screen.getByRole('button', { name: 'Move phase 1 up' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Move phase 2 down' })).toBeDisabled()
    await user.click(screen.getByRole('button', { name: 'Move phase 1 down' }))
    expect(phase(1).getByLabelText('Key')).toHaveValue('job')
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

    await user.click(screen.getByRole('button', { name: 'Delete phase 1' }))

    expect(screen.queryByRole('group', { name: 'Phase 2' })).not.toBeInTheDocument()
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

  it('sends nothing for an empty key or a key another phase has, and says so on the phase', async () => {
    const saved = recorder(() => Response.json(narrativeFlows))
    const { user } = await openEditor('heist', { [put]: saved.handler })

    await replace(user, phase(2).getByLabelText('Key'), 'setup')
    await user.click(save())
    expect(await phase(2).findByText('Another phase has this key.')).toBeInTheDocument()
    await user.clear(phase(1).getByLabelText('Key'))
    await user.clear(flowKey())
    await user.click(save())

    expect(await phase(1).findByText('Enter a key.')).toBeInTheDocument()
    expect(screen.getAllByText('Enter a key.')).toHaveLength(2)
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
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('puts a message about a phase field on its card', async () => {
    const { user } = await openEditor('heist', {
      [put]: json({ error: 'flows[0].phases[1].key: duplicate phase key "setup"' }, 422),
    })

    await user.click(save())

    expect(await phase(2).findByText('duplicate phase key "setup"')).toBeInTheDocument()
    expect(phase(1).queryByText(/duplicate/)).not.toBeInTheDocument()
  })

  it('lists a message about the selection or a hook on its card, path included', async () => {
    const { user } = await openEditor('heist', {
      [put]: json(
        { error: 'flows[0].phases[0].selection.sceneTypes[0]: unknown Scene Type "x"' },
        422,
      ),
    })

    await user.click(save())

    expect(
      await phase(1).findByText('selection.sceneTypes[0]: unknown Scene Type "x"'),
    ).toBeInTheDocument()
  })

  it('keeps a message on the phase it is about when phases are moved or deleted before the next save', async () => {
    const { user } = await openEditor('heist', {
      [put]: json({ error: 'flows[0].phases[1].key: duplicate phase key "setup"' }, 422),
    })

    await user.click(save())
    expect(await phase(2).findByText(/duplicate phase key/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Move phase 2 up' }))
    expect(phase(1).getByText(/duplicate phase key/)).toBeInTheDocument()
    expect(phase(2).queryByText(/duplicate phase key/)).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Add phase' }))
    await user.click(screen.getByRole('button', { name: 'Delete phase 2' }))
    expect(phase(1).getByText(/duplicate phase key/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Delete phase 1' }))
    expect(screen.queryByText(/duplicate phase key/)).not.toBeInTheDocument()
  })

  it.each([
    ['flows[0].phases[5].key: nope', 'a phase the page does not have'],
    ['flows[1].name: nope', 'another flow'],
    ['flows[0].phases: at most 20 phases', 'the phase list'],
  ])('shows %s on the page, path included (%s)', async (message) => {
    const { user } = await openEditor('heist', { [put]: json({ error: message }, 422) })

    await user.click(save())

    expect(await screen.findByRole('alert')).toHaveTextContent(message)
  })

  it('says the flow is gone when the server cannot find it', async () => {
    const { user } = await openEditor('heist', {
      [put]: json({ error: 'The draft has no flow "heist".' }, 404),
    })

    await user.click(save())

    expect(await screen.findByRole('alert')).toHaveTextContent('The draft has no flow "heist".')
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

    await replace(user, phase(1).getByLabelText('Name'), 'Mine')
    await user.click(save())
    expect(await screen.findByRole('alert')).toHaveTextContent('was changed by another request')
    await user.click(screen.getByRole('button', { name: 'Reload the latest version' }))

    await waitFor(() => {
      expect(flowName()).toHaveValue('Changed elsewhere')
    })
    expect(phase(1).getByLabelText('Name')).toHaveValue('Setup')
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

    await replace(user, phase(1).getByLabelText('Name'), 'Mine')
    await user.click(save())
    await user.click(await screen.findByRole('button', { name: 'Reload the latest version' }))

    expect(await screen.findByText(note)).toBeInTheDocument()
    expect(phase(1).getByLabelText('Name')).toHaveValue('Mine')
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

    await replace(user, phase(1).getByLabelText('Name'), 'Mine')
    await user.click(save())
    await user.click(await screen.findByRole('button', { name: 'Reload the latest version' }))

    expect(await screen.findByText(/Couldn.t reload the latest version/)).toBeInTheDocument()
    expect(phase(1).getByLabelText('Name')).toHaveValue('Mine')
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
