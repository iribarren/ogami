import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

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
