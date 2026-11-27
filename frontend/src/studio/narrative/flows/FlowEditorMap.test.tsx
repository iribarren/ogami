import { act, fireEvent, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { chooseOption } from '@/test/chooseOption'
import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { draft, narrativeFlows, oracles, validDraft } from '../../fixtures'

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

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

/** The narrative with `heist` changed: `phases` replaces its phases. */
const withPhases = (phases: Record<string, unknown>[]) => ({
  ...narrativeFlows,
  flows: [
    { key: 'heist', definition: { ...heist, phases }, usedBy: [] },
    ...narrativeFlows.flows.slice(1),
  ],
})

const heistPhases = heist.phases as Record<string, unknown>[]

async function openEditor(path: string, handlers: Record<string, FakeHandler> = {}) {
  const user = userEvent.setup()
  const rendered = renderAppAt(`/studio/game-systems/my-draft/flows/${path}`, {
    'GET /api/auth/me': signedInAs('GAME_MANAGER'),
    [`GET ${base}`]: json(draft),
    [`GET ${base}/validation`]: json(validDraft),
    [`GET ${base}/narrative`]: json(narrativeFlows),
    [`GET ${base}/oracles`]: json(oracles),
    ...handlers,
  })
  await screen.findByRole('form', { name: 'Flow' })
  return { user, ...rendered }
}

const map = () => screen.findByRole('region', { name: 'Flow map' })
const search = (router: { state: { location: { search: unknown } } }) =>
  router.state.location.search

describe('the Map tab of the flow editor', () => {
  it('opens on the Panel, with the Panel tab selected and no map', async () => {
    await openEditor('heist')

    expect(screen.getByRole('tab', { name: 'Panel' })).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByRole('tab', { name: 'Map' })).toHaveAttribute('aria-selected', 'false')
    expect(screen.queryByRole('region', { name: 'Flow map' })).not.toBeInTheDocument()
    expect(screen.getByRole('tabpanel')).toHaveAccessibleName('Panel')
  })

  it('opens the Map from the URL and keeps the outline beside it', async () => {
    await openEditor('heist?view=map')

    const region = within(await map())
    expect(screen.getByRole('tab', { name: 'Map' })).toHaveAttribute('aria-selected', 'true')
    expect(region.getByRole('group', { name: 'Flow diagram: Heist' })).toBeInTheDocument()
    expect(region.getByRole('button', { name: 'Phase: Setup' })).toBeInTheDocument()
    expect(region.getByRole('button', { name: 'Phase: The job' })).toBeInTheDocument()
    expect(region.getByRole('button', { name: 'Selection: Player chooses' })).toBeInTheDocument()
    expect(screen.getByRole('navigation', { name: 'Flow outline' })).toBeInTheDocument()
  })

  it('ignores a view it does not know', async () => {
    await openEditor('heist?view=nope')

    expect(screen.getByRole('tab', { name: 'Panel' })).toHaveAttribute('aria-selected', 'true')
  })

  it('switches tabs by click and by arrow key, and the URL says which', async () => {
    const { user, router } = await openEditor('heist')

    await user.click(screen.getByRole('tab', { name: 'Map' }))
    await map()
    expect(search(router)).toMatchObject({ view: 'map' })

    screen.getByRole('tab', { name: 'Map' }).focus()
    await user.keyboard('{ArrowLeft}')

    expect(screen.getByRole('tab', { name: 'Panel' })).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByRole('tab', { name: 'Panel' })).toHaveFocus()
    expect(search(router)).not.toHaveProperty('view')
    expect(screen.queryByRole('region', { name: 'Flow map' })).not.toBeInTheDocument()
  })

  it('draws the unsaved edits of the form', async () => {
    const { user } = await openEditor('heist')
    const form = within(screen.getByRole('form', { name: 'Flow' }))

    await user.click(
      within(screen.getByRole('navigation', { name: 'Flow outline' })).getByRole('button', {
        name: /^1\. /,
      }),
    )
    await user.clear(form.getByLabelText('Name'))
    await user.type(form.getByLabelText('Name'), 'Casing')
    await user.click(screen.getByRole('tab', { name: 'Map' }))

    expect(await within(await map()).findByRole('button', { name: 'Phase: Casing' })).toBeVisible()
  })

  it('opens the panel of a phase, a selection and a hook when its node is picked', async () => {
    const phases = [
      { ...heistPhases[0], worldTurn: [{ key: 'w', kind: 'prompt', title: 'W' }] },
      heistPhases[1] ?? {},
    ]
    const { user, router } = await openEditor('heist?view=map', {
      [`GET ${base}/narrative`]: json(withPhases(phases)),
    })
    const region = within(await map())

    await user.click(region.getByRole('button', { name: 'Phase: The job' }))
    expect(search(router)).toEqual({ node: 'phases.1' })
    expect(screen.getByRole('tab', { name: 'Panel' })).toHaveAttribute('aria-selected', 'true')
    expect(
      within(screen.getByRole('form', { name: 'Flow' })).getByRole('heading', { level: 2 }),
    ).toHaveTextContent('2. The job')

    await user.click(screen.getByRole('tab', { name: 'Map' }))
    await user.click(within(await map()).getByRole('button', { name: 'Selection: Player chooses' }))
    expect(search(router)).toEqual({ node: 'phases.1.selection' })

    await user.click(screen.getByRole('tab', { name: 'Map' }))
    await user.click(within(await map()).getByRole('button', { name: 'Hook: World turn, 1 step' }))
    expect(search(router)).toEqual({ node: 'phases.0.hooks.worldTurn' })
    expect(
      within(screen.getByRole('form', { name: 'Flow' })).getByRole('heading', { level: 2 }),
    ).toHaveTextContent('World turn')
  })

  it('opens a node with Enter and with Space from the keyboard', async () => {
    const { user, router } = await openEditor('heist?view=map')
    const region = within(await map())

    region.getByRole('button', { name: 'Phase: The job' }).focus()
    await user.keyboard('{Enter}')
    expect(search(router)).toEqual({ node: 'phases.1' })

    await user.click(screen.getByRole('tab', { name: 'Map' }))
    within(await map())
      .getByRole('button', { name: 'Phase: Setup' })
      .focus()
    await user.keyboard(' ')
    expect(search(router)).toEqual({ node: 'phases.0' })
  })

  it('goes to the Scene Type page from a Scene Type node', async () => {
    const { user, router } = await openEditor('heist?view=map')

    await user.click(within(await map()).getByRole('button', { name: 'Scene Type: Quiet' }))

    expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft/scene-types/quiet')
  })

  it('asks before leaving for a Scene Type with unsaved changes, and stays when told to', async () => {
    const { user, router } = await openEditor('heist')
    const form = within(screen.getByRole('form', { name: 'Flow' }))
    await user.type(form.getByLabelText('Name'), ' II')
    await user.click(screen.getByRole('tab', { name: 'Map' }))

    await user.click(within(await map()).getByRole('button', { name: 'Scene Type: Quiet' }))

    const dialog = await screen.findByRole('dialog', { name: 'Leave without saving?' })
    await user.click(within(dialog).getByRole('button', { name: 'Stay' }))
    expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft/flows/heist')
  })

  it('marks a Scene Type the draft does not have, and still draws the rest', async () => {
    const phases = [
      { ...heistPhases[0], selection: { rule: 'sequence', sceneTypes: ['ambush', 'ghost'] } },
      heistPhases[1] ?? {},
    ]
    await openEditor('heist?view=map', { [`GET ${base}/narrative`]: json(withPhases(phases)) })
    const region = within(await map())

    expect(
      region.getByRole('button', {
        name: 'Phase: Setup. Issues: Scene Type "ghost" is not in the draft',
      }),
    ).toBeInTheDocument()
    expect(region.getByRole('button', { name: 'Phase: The job' })).toBeInTheDocument()
  })

  it('marks an oracle table the draft does not have on its table node', async () => {
    await openEditor('side-job?view=map')

    expect(
      within(await map()).getByRole('button', {
        name: 'Selection table: places. Issues: table "places" is not in the draft',
      }),
    ).toBeInTheDocument()
  })

  it('shows a refused save on its node and stays on the Map', async () => {
    const { user, router } = await openEditor('heist?view=map', {
      [put]: json({ error: 'flows[0].phases[1].key: duplicate phase key "setup"' }, 422),
    })
    await map()

    await user.click(screen.getByRole('button', { name: 'Save' }))

    expect(
      await within(await map()).findByRole('button', {
        name: 'Phase: The job. Issues: duplicate phase key "setup"',
      }),
    ).toBeInTheDocument()
    expect(search(router)).toMatchObject({ view: 'map', node: 'phases.1' })
    expect(screen.getByRole('alert')).toHaveTextContent('Not saved: 1 error')
  })

  it('shows a check that stops the save, a phase without a Scene Type, on its box', async () => {
    const phases = [
      { ...heistPhases[0], selection: { rule: 'sequence', sceneTypes: [] } },
      heistPhases[1] ?? {},
    ]
    const { user } = await openEditor('heist?view=map', {
      [`GET ${base}/narrative`]: json(withPhases(phases)),
    })
    await map()

    await user.click(screen.getByRole('button', { name: 'Save' }))

    expect(
      await within(await map()).findByRole('button', {
        name: 'Phase: Setup. Issues: Pick at least one Scene Type.',
      }),
    ).toBeInTheDocument()
  })
})

describe('editing on the Map', () => {
  beforeAll(() => {
    // jsdom has no layout: the drawing's matrix is the identity, so client points are drawing points.
    Object.defineProperty(SVGElement.prototype, 'getScreenCTM', {
      configurable: true,
      value: () => ({ a: 1, b: 0, c: 0, d: 1, e: 0, f: 0 }),
    })
  })

  const press = { pointerId: 1, isPrimary: true, button: 0 }
  const rectOf = (element: Element) => {
    const rect = element.tagName === 'rect' ? element : element.querySelector('rect')
    const at = (name: string) => Number(rect?.getAttribute(name))
    return { x: at('x'), y: at('y'), width: at('width'), height: at('height') }
  }
  const centreOf = (element: Element) => {
    const { x, y, width, height } = rectOf(element)
    return { clientX: x + width / 2, clientY: y + height / 2 }
  }
  /** A drag of `source` that lets go at `to`, or on the middle of `onto`. */
  function drag(source: Element, onto: Element | { clientX: number; clientY: number }) {
    const to = onto instanceof Element ? centreOf(onto) : onto
    fireEvent.pointerDown(source, { ...press, ...centreOf(source) })
    fireEvent.pointerMove(source, { ...press, ...to })
    fireEvent.pointerUp(source, { ...press, ...to })
  }
  const order = (region: HTMLElement) =>
    [...region.querySelectorAll('[data-phase-id]')].map((phase) =>
      phase.getAttribute('data-phase-id'),
    )
  const said = () => screen.getByRole('status')

  it('reorders phases by dragging one onto another, as the outline does', async () => {
    await openEditor('heist?view=map')
    const region = await map()
    const inside = within(region)

    drag(
      inside.getByRole('button', { name: 'Phase: Setup' }),
      inside.getByRole('button', { name: 'Phase: The job' }),
    )

    expect(order(region)).toEqual(['phase:job', 'phase:setup'])
    expect(said()).toHaveTextContent('Moved phase "Setup" after "The job".')
    expect(screen.getByText('Unsaved changes')).toBeInTheDocument()
    expect(
      within(screen.getByRole('navigation', { name: 'Flow outline' })).getByRole('button', {
        name: /^1\. The job/,
      }),
    ).toBeInTheDocument()
  })

  it('opens nothing when a drag ends on a button, and still opens on a plain click', async () => {
    const { user, router } = await openEditor('heist?view=map')
    const inside = within(await map())

    drag(
      inside.getByRole('button', { name: 'Phase: Setup' }),
      inside.getByRole('button', { name: 'Phase: The job' }),
    )
    fireEvent.click(inside.getByRole('button', { name: 'Phase: Setup' }))
    expect(search(router)).toEqual({ view: 'map' })

    await user.click(inside.getByRole('button', { name: 'Phase: The job' }))
    expect(search(router)).toEqual({ node: 'phases.0' })
  })

  it('says why a drop outside every phase changes nothing', async () => {
    await openEditor('heist?view=map')
    const region = await map()
    const inside = within(region)

    drag(inside.getByRole('button', { name: 'Phase: Setup' }), { clientX: -40, clientY: -40 })

    expect(order(region)).toEqual(['phase:setup', 'phase:job'])
    expect(said()).toHaveTextContent('Dropped outside every phase: "Setup" stays.')
    expect(screen.queryByText('Unsaved changes')).not.toBeInTheDocument()
  })

  it('cancels a drag on Escape', async () => {
    await openEditor('heist?view=map')
    const region = await map()
    const inside = within(region)
    const setup = inside.getByRole('button', { name: 'Phase: Setup' })
    const to = centreOf(inside.getByRole('button', { name: 'Phase: The job' }))

    fireEvent.pointerDown(setup, { ...press, ...centreOf(setup) })
    fireEvent.pointerMove(setup, { ...press, ...to })
    fireEvent.keyDown(window, { key: 'Escape' })
    fireEvent.pointerUp(setup, { ...press, ...to })

    expect(order(region)).toEqual(['phase:setup', 'phase:job'])
    expect(said()).toHaveTextContent('')
  })

  it('switches a phase between once and loop from its mode chip, without opening it', async () => {
    const { user, router } = await openEditor('heist?view=map')
    const inside = within(await map())

    await user.click(inside.getByRole('button', { name: 'Mode of "Setup": once. Switch to loop' }))

    expect(
      inside.getByRole('button', { name: 'Mode of "Setup": loop. Switch to once' }),
    ).toBeVisible()
    expect(said()).toHaveTextContent('Phase "Setup" now loops.')
    expect(screen.getByText('Unsaved changes')).toBeInTheDocument()
    expect(search(router)).toEqual({ view: 'map' })
  })

  it('moves and switches the phase last focused with the action bar, and the selection follows', async () => {
    const { user, router } = await openEditor('heist?view=map&node=phases.0')
    const region = await map()
    const inside = within(region)
    const bar = within(screen.getByRole('group', { name: 'Map actions' }))
    expect(bar.getByText(/Pick a phase/)).toBeInTheDocument()

    act(() => {
      inside.getByRole('button', { name: 'Phase: Setup' }).focus()
    })
    expect(bar.getByText('Phase "Setup"')).toBeInTheDocument()
    expect(bar.getByRole('button', { name: 'Move earlier' })).toBeDisabled()

    await user.click(bar.getByRole('button', { name: 'Move later' }))
    expect(order(region)).toEqual(['phase:job', 'phase:setup'])
    expect(said()).toHaveTextContent('Moved phase "Setup" after "The job".')
    expect(bar.getByRole('button', { name: 'Move later' })).toBeDisabled()
    expect(search(router)).toEqual({ view: 'map', node: 'phases.1' })

    await user.click(bar.getByRole('button', { name: 'Switch to loop' }))
    expect(said()).toHaveTextContent('Phase "Setup" now loops.')
  })

  it('keeps the save errors on the phase that moved', async () => {
    const { user } = await openEditor('heist?view=map', {
      [put]: json({ error: 'flows[0].phases[1].key: duplicate phase key "setup"' }, 422),
    })
    const inside = within(await map())

    drag(
      inside.getByRole('button', { name: 'Phase: Setup' }),
      inside.getByRole('button', { name: 'Phase: The job' }),
    )
    await user.click(screen.getByRole('button', { name: 'Save' }))

    // After the move "The job" is the first phase: the refusal is about the phase at index 1, Setup.
    expect(
      await within(await map()).findByRole('button', {
        name: 'Phase: Setup. Issues: duplicate phase key "setup"',
      }),
    ).toBeInTheDocument()
  })

  it('says above the map what no node can carry', async () => {
    const sceneTypes = [
      ...narrativeFlows.sceneTypes,
      { key: 'x', definition: { name: 'No key' }, usedBy: [] },
    ]
    await openEditor('heist?view=map', {
      [`GET ${base}/narrative`]: json({ ...narrativeFlows, sceneTypes }),
    })

    expect(
      await within(await map()).findByText(
        'A Scene Type of the narrative cannot be read: must be a non-empty string.',
      ),
    ).toBeVisible()
  })
})

describe('Scene Types on the Map', () => {
  const ctm = Object.getOwnPropertyDescriptor(SVGElement.prototype, 'getScreenCTM')
  beforeAll(() => {
    Object.defineProperty(SVGElement.prototype, 'getScreenCTM', {
      configurable: true,
      value: () => ({ a: 1, b: 0, c: 0, d: 1, e: 0, f: 0 }),
    })
  })
  afterAll(() => {
    // jsdom has none: put back what was there, or remove the stub.
    if (ctm === undefined) {
      Reflect.deleteProperty(SVGElement.prototype, 'getScreenCTM')
    } else {
      Object.defineProperty(SVGElement.prototype, 'getScreenCTM', ctm)
    }
  })

  const press = { pointerId: 1, isPrimary: true, button: 0 }
  const rectOf = (element: Element) => {
    const rect = element.tagName === 'rect' ? element : element.querySelector('rect')
    const at = (name: string) => Number(rect?.getAttribute(name))
    return { x: at('x'), y: at('y'), width: at('width'), height: at('height') }
  }
  const node = (region: HTMLElement, id: string) => {
    const found = region.querySelector(`[data-node-id="${id}"]`)
    if (found === null) {
      throw new Error(`Expected the node ${id}.`)
    }
    return found
  }
  /** The point `dx` px right of the middle of a node's rectangle. */
  const besideNode = (region: HTMLElement, id: string, dx: number) => {
    const { x, y, width, height } = rectOf(node(region, id))
    return { clientX: x + width / 2 + dx, clientY: y + height / 2 }
  }
  const ofPhase = (region: HTMLElement, phase: string) => {
    const { x, y, width, height } = rectOf(
      region.querySelector(`[data-phase-id="phase:${phase}"]`) ?? region,
    )
    return { clientX: x + width - 12, clientY: y + height - 12 }
  }
  /** Presses at `from` (a palette item has no drawing position), moves past the threshold, lets go at `to`. */
  function drag(source: Element, from: { clientX: number; clientY: number }, to: typeof from) {
    fireEvent.pointerDown(source, { ...press, ...from })
    fireEvent.pointerMove(source, { ...press, ...to })
    fireEvent.pointerUp(source, { ...press, ...to })
  }
  const nowhere = { clientX: -40, clientY: -40 }
  const said = () => screen.getByRole('status')
  const palette = (name: string) =>
    within(screen.getByRole('list', { name: 'Scene Types to drag' })).getByText(name)
  /** The Scene Type nodes of a phase, left to right. */
  const scenesOf = (region: HTMLElement, phase: string) =>
    [...region.querySelectorAll(`[data-node-id^="scene:${phase}:"]`)]
      .sort((a, b) => rectOf(a).x - rectOf(b).x)
      .map((found) => found.getAttribute('data-node-id'))
  const sequence = (...sceneTypes: string[]) =>
    withPhases([
      { ...heistPhases[0], selection: { rule: 'sequence', sceneTypes } },
      heistPhases[1] ?? {},
    ])

  it('lists the Scene Types of the GameSystem beside the Map', async () => {
    await openEditor('heist?view=map')
    await map()

    const list = within(screen.getByRole('list', { name: 'Scene Types to drag' }))
    expect(list.getAllByRole('listitem').map((item) => item.textContent)).toEqual([
      'Ambush',
      'Quiet',
      'Odd',
    ])
  })

  it('adds a palette Scene Type to a sequence where it is dropped, or at the end', async () => {
    await openEditor('heist?view=map', {
      [`GET ${base}/narrative`]: json(sequence('quiet')),
    })
    const region = await map()

    drag(
      palette('Ambush'),
      { clientX: -90, clientY: 5 },
      besideNode(region, 'scene:setup:quiet', -30),
    )
    expect(scenesOf(region, 'setup')).toEqual(['scene:setup:ambush', 'scene:setup:quiet'])
    expect(said()).toHaveTextContent('Added "Ambush" to phase "Setup".')
    expect(screen.getByText('Unsaved changes')).toBeInTheDocument()

    drag(palette('Odd'), { clientX: -90, clientY: 5 }, besideNode(region, 'scene:setup:quiet', 30))
    expect(scenesOf(region, 'setup')).toEqual([
      'scene:setup:ambush',
      'scene:setup:quiet',
      'scene:setup:odd',
    ])
  })

  it('appends a palette Scene Type to a player selection', async () => {
    await openEditor('heist?view=map', {
      [`GET ${base}/narrative`]: json(
        withPhases([
          heistPhases[0] ?? {},
          { ...heistPhases[1], selection: { rule: 'player', sceneTypes: ['ambush'] } },
        ]),
      ),
    })
    const region = await map()

    drag(palette('Quiet'), { clientX: -90, clientY: 5 }, ofPhase(region, 'job'))

    expect(said()).toHaveTextContent('Added "Quiet" to phase "The job".')
    expect(scenesOf(region, 'job')).toContain('scene:job:quiet')
  })

  it('refuses a Scene Type for an oracle phase, and a drop on nothing', async () => {
    await openEditor('side-job?view=map')
    const region = await map()

    drag(palette('Quiet'), { clientX: -90, clientY: 5 }, ofPhase(region, 'main'))
    expect(said()).toHaveTextContent(
      'Phase "Main" draws its Scene Types from table "places": edit the table instead.',
    )

    drag(palette('Quiet'), { clientX: -90, clientY: 5 }, nowhere)
    expect(said()).toHaveTextContent('Dropped outside every phase: nothing changed.')
    expect(screen.queryByText('Unsaved changes')).not.toBeInTheDocument()
  })

  it('refuses a Scene Type the phase lists already', async () => {
    await openEditor('heist?view=map')
    const region = await map()

    drag(palette('Ambush'), { clientX: -90, clientY: 5 }, ofPhase(region, 'setup'))

    expect(said()).toHaveTextContent('"Ambush" is already in phase "Setup".')
    expect(screen.queryByText('Unsaved changes')).not.toBeInTheDocument()
  })

  it('removes a selected Scene Type dragged out of every phase, and the bar leaves its node', async () => {
    await openEditor('heist?view=map')
    const region = await map()
    const quiet = node(region, 'scene:job:quiet')

    drag(quiet, besideNode(region, 'scene:job:quiet', 0), nowhere)

    expect(said()).toHaveTextContent('Removed "Quiet" from phase "The job".')
    expect(region.querySelector('[data-node-id="scene:job:quiet"]')).toBeNull()
    expect(
      within(screen.getByRole('group', { name: 'Map actions' })).getByText('Phase "The job"'),
    ).toBeInTheDocument()
    expect(screen.getByText('Unsaved changes')).toBeInTheDocument()
  })

  it('moves a Scene Type to another phase, and changes nothing when that phase refuses it', async () => {
    await openEditor('heist?view=map', { [`GET ${base}/narrative`]: json(sequence('ambush')) })
    const region = await map()

    drag(
      node(region, 'scene:job:ambush'),
      besideNode(region, 'scene:job:ambush', 0),
      ofPhase(region, 'setup'),
    )
    expect(said()).toHaveTextContent('"Ambush" is already in phase "Setup".')
    expect(scenesOf(region, 'job')).toEqual(['scene:job:ambush', 'scene:job:quiet'])
    expect(screen.queryByText('Unsaved changes')).not.toBeInTheDocument()

    drag(
      node(region, 'scene:job:quiet'),
      besideNode(region, 'scene:job:quiet', 0),
      besideNode(region, 'scene:setup:ambush', -30),
    )
    expect(said()).toHaveTextContent('Moved "Quiet" from phase "The job" to phase "Setup".')
    expect(scenesOf(region, 'setup')).toEqual(['scene:setup:quiet', 'scene:setup:ambush'])
    expect(scenesOf(region, 'job')).toEqual(['scene:job:ambush'])
  })

  it('repositions a Scene Type inside its sequence, and leaves a player selection as it is', async () => {
    await openEditor('heist?view=map', {
      [`GET ${base}/narrative`]: json(sequence('ambush', 'quiet')),
    })
    const region = await map()

    drag(
      node(region, 'scene:setup:quiet'),
      besideNode(region, 'scene:setup:quiet', 0),
      besideNode(region, 'scene:setup:ambush', -30),
    )
    expect(scenesOf(region, 'setup')).toEqual(['scene:setup:quiet', 'scene:setup:ambush'])
    expect(said()).toHaveTextContent('Moved "Quiet" to place 1 of phase "Setup".')

    drag(
      node(region, 'scene:job:quiet'),
      besideNode(region, 'scene:job:quiet', 0),
      besideNode(region, 'scene:job:ambush', -30),
    )
    expect(said()).toHaveTextContent('"Quiet" stays where it is in phase "The job".')
  })

  it('keeps the other Scene Types draggable when a selection lists one the GameSystem lacks', async () => {
    await openEditor('heist?view=map', {
      [`GET ${base}/narrative`]: json(sequence('ghost', 'quiet')),
    })
    const region = await map()

    drag(node(region, 'scene:setup:quiet'), besideNode(region, 'scene:setup:quiet', 0), nowhere)

    expect(said()).toHaveTextContent('Removed "Quiet" from phase "Setup".')
  })

  it('adds, removes and moves Scene Types with the action bar, and saves what the Map changed', async () => {
    const sent: unknown[] = []
    const { user } = await openEditor('heist?view=map', {
      [`GET ${base}/narrative`]: json(sequence('ambush')),
      [put]: async (request) => {
        sent.push(await request.clone().json())
        return Response.json(narrativeFlows)
      },
    })
    const region = await map()
    const bar = () => within(screen.getByRole('group', { name: 'Map actions' }))

    act(() => {
      within(region).getByRole('button', { name: 'Phase: Setup' }).focus()
    })
    await chooseOption(user, 'Scene Type to add', 'Quiet', bar())
    await user.click(bar().getByRole('button', { name: 'Add Scene Type' }))
    expect(said()).toHaveTextContent('Added "Quiet" to phase "Setup".')
    await chooseOption(user, 'Scene Type to add', 'Quiet', bar())
    await user.click(bar().getByRole('button', { name: 'Add Scene Type' }))
    expect(said()).toHaveTextContent('"Quiet" is already in phase "Setup".')

    act(() => {
      region.querySelector<HTMLElement>('[data-node-id="scene:setup:quiet"]')?.focus()
    })
    expect(bar().getByText('Scene Type "Quiet" in phase "Setup"')).toBeInTheDocument()
    expect(bar().getByRole('button', { name: 'Move later' })).toBeDisabled()
    await user.click(bar().getByRole('button', { name: 'Move earlier' }))
    expect(scenesOf(region, 'setup')).toEqual(['scene:setup:quiet', 'scene:setup:ambush'])
    expect(bar().getByRole('button', { name: 'Move earlier' })).toBeDisabled()

    await user.click(bar().getByRole('button', { name: 'Remove from the selection' }))
    expect(said()).toHaveTextContent('Removed "Quiet" from phase "Setup".')
    expect(bar().getByText('Phase "Setup"')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Save' }))
    await vi.waitFor(() => {
      expect(sent).toHaveLength(1)
    })
    expect(JSON.stringify(sent[0])).toContain('"sceneTypes":["ambush"]')
  })

  it('starts the Add Scene Type pick empty on every phase the bar moves to', async () => {
    const { user } = await openEditor('heist?view=map')
    const region = await map()
    const bar = () => within(screen.getByRole('group', { name: 'Map actions' }))

    act(() => {
      within(region).getByRole('button', { name: 'Phase: Setup' }).focus()
    })
    await chooseOption(user, 'Scene Type to add', 'Quiet', bar())
    expect(bar().getByRole('combobox', { name: 'Scene Type to add' })).toHaveTextContent('Quiet')

    act(() => {
      within(region).getByRole('button', { name: 'Phase: The job' }).focus()
    })

    expect(bar().getByRole('combobox', { name: 'Scene Type to add' })).toHaveTextContent(
      'Pick a Scene Type',
    )
  })

  it('offers no way to add a Scene Type to an oracle phase from the bar', async () => {
    await openEditor('side-job?view=map')
    const region = await map()

    act(() => {
      within(region).getByRole('button', { name: 'Phase: Main' }).focus()
    })

    expect(screen.queryByRole('button', { name: 'Add Scene Type' })).not.toBeInTheDocument()
  })
})

describe('arrows on the Map', () => {
  const ctm = Object.getOwnPropertyDescriptor(SVGElement.prototype, 'getScreenCTM')
  beforeAll(() => {
    Object.defineProperty(SVGElement.prototype, 'getScreenCTM', {
      configurable: true,
      value: () => ({ a: 1, b: 0, c: 0, d: 1, e: 0, f: 0 }),
    })
  })
  afterAll(() => {
    if (ctm === undefined) {
      Reflect.deleteProperty(SVGElement.prototype, 'getScreenCTM')
    } else {
      Object.defineProperty(SVGElement.prototype, 'getScreenCTM', ctm)
    }
  })

  const press = { pointerId: 1, isPrimary: true, button: 0 }
  const rectOf = (element: Element) => {
    const rect = element.tagName === 'rect' ? element : element.querySelector('rect')
    const at = (name: string) => Number(rect?.getAttribute(name))
    return { x: at('x'), y: at('y'), width: at('width'), height: at('height') }
  }
  const inside = (region: HTMLElement, selector: string) => {
    const found = region.querySelector(selector)
    if (found === null) {
      throw new Error(`Expected ${selector}.`)
    }
    return found
  }
  const centreOf = (region: HTMLElement, id: string) => {
    const { x, y, width, height } = rectOf(inside(region, `[data-node-id="${id}"]`))
    return { clientX: x + width / 2, clientY: y + height / 2 }
  }
  const phaseCorner = (region: HTMLElement, phase: string) => {
    const { x, y, width, height } = rectOf(inside(region, `[data-phase-id="phase:${phase}"]`))
    return { clientX: x + width - 12, clientY: y + height - 12 }
  }
  /** Draws an arrow: presses the port of `id`, moves past the threshold, lets go at `to`. */
  function arrow(region: HTMLElement, id: string, to: { clientX: number; clientY: number }) {
    const port = inside(region, `[data-port-id="${id}"]`)
    fireEvent.pointerDown(port, { ...press, clientX: -60, clientY: -60 })
    fireEvent.pointerMove(port, { ...press, ...to })
    fireEvent.pointerUp(port, { ...press, ...to })
  }
  const said = () => screen.getByRole('status')
  const opening = { phaseOpening: [{ key: 'open', kind: 'prompt', title: 'Open' }] }
  const withOpening = () =>
    json(withPhases([{ ...heistPhases[0], ...opening }, heistPhases[1] ?? {}]))

  it('adds a transition to a Scene Type of its phase to the first step of a hook, and saves it', async () => {
    const sent: unknown[] = []
    const { user } = await openEditor('heist?view=map', {
      [`GET ${base}/narrative`]: withOpening(),
      [put]: async (request) => {
        sent.push(await request.clone().json())
        return Response.json(narrativeFlows)
      },
    })
    const region = await map()

    arrow(region, 'hook:setup:phaseOpening', centreOf(region, 'scene:setup:ambush'))

    expect(said()).toHaveTextContent(
      'Added next scene "Ambush" to step "Open" of the hook "Phase opening" of phase "Setup".',
    )
    expect(screen.getByText('Unsaved changes')).toBeInTheDocument()
    expect(region.querySelector('[data-kind="nextScene"]')).not.toBeNull()
    await user.click(screen.getByRole('button', { name: 'Save' }))
    await vi.waitFor(() => {
      expect(sent).toHaveLength(1)
    })
    expect(JSON.stringify(sent[0])).toContain(
      '"effects":[{"kind":"nextScene","sceneType":"ambush"}]',
    )
  })

  it('ends a phase by an arrow to the next phase, once, and refuses the same arrow twice', async () => {
    await openEditor('heist?view=map', { [`GET ${base}/narrative`]: withOpening() })
    const region = await map()

    arrow(region, 'hook:setup:phaseOpening', phaseCorner(region, 'job'))
    expect(said()).toHaveTextContent(
      'Added end the phase to step "Open" of the hook "Phase opening" of phase "Setup".',
    )
    expect(region.querySelector('[data-kind="endPhase"]')).not.toBeNull()

    arrow(region, 'hook:setup:phaseOpening', phaseCorner(region, 'job'))
    expect(said()).toHaveTextContent('Step "Open" already has this effect: end the phase.')
  })

  it('refuses an arrow that breaks the rules, and changes nothing', async () => {
    await openEditor('heist?view=map', { [`GET ${base}/narrative`]: withOpening() })
    const region = await map()
    const hook = 'hook:setup:phaseOpening'

    arrow(region, hook, phaseCorner(region, 'setup'))
    expect(said()).toHaveTextContent('That is phase "Setup" itself')
    arrow(region, hook, centreOf(region, 'scene:job:quiet'))
    expect(said()).toHaveTextContent(
      '"Quiet" is in phase "The job": a transition reaches a Scene Type of its own phase only.',
    )
    arrow(region, hook, { clientX: -40, clientY: -40 })
    expect(said()).toHaveTextContent('Dropped on nothing: no transition added.')
    arrow(region, 'scene:setup:ambush', phaseCorner(region, 'job'))
    expect(said()).toHaveTextContent('is added on the Scene Type page')
    expect(screen.queryByText('Unsaved changes')).not.toBeInTheDocument()
  })

  it('refuses an arrow that starts at the table of an oracle phase', async () => {
    await openEditor('side-job?view=map')
    const region = await map()

    arrow(region, 'table:main', phaseCorner(region, 'main'))
    expect(said()).toHaveTextContent('Its effects sit on table entries')
  })

  it('refuses an arrow from a hook whose first step is kept as stored', async () => {
    await openEditor('heist?view=map', {
      [`GET ${base}/narrative`]: json(
        withPhases([
          { ...heistPhases[0], phaseOpening: [{ kind: 'mystery' }] },
          heistPhases[1] ?? {},
        ]),
      ),
    })
    const region = await map()

    arrow(region, 'hook:setup:phaseOpening', centreOf(region, 'scene:setup:ambush'))

    expect(said()).toHaveTextContent('cannot be edited here: open its panel.')
    expect(screen.queryByText('Unsaved changes')).not.toBeInTheDocument()
  })

  it('leaves the drag of a node body as it was: a port does not move its node', async () => {
    await openEditor('heist?view=map')
    const region = await map()
    const body = inside(region, '[data-node-id="scene:job:quiet"]')

    fireEvent.pointerDown(body, { ...press, ...centreOf(region, 'scene:job:quiet') })
    fireEvent.pointerMove(body, { ...press, clientX: -40, clientY: -40 })
    fireEvent.pointerUp(body, { ...press, clientX: -40, clientY: -40 })

    expect(said()).toHaveTextContent('Removed "Quiet" from phase "The job".')
  })
})
