import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { openStepCards } from '@/test/openStepCards'
import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { draft, narrativeFlows, oracles } from '../../fixtures'
import { packSystem } from '../../library/fixtures'

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
const changed = {
  error: 'GameSystem "my-draft" was changed by another request. Reload it and try again.',
}

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

/** Opens the flow `heist` on the World turn hook of its first phase, with a `table` step added. */
async function openTableStep(handlers: Record<string, FakeHandler>) {
  const user = userEvent.setup()
  const { router } = renderAppAt('/studio/game-systems/my-draft/flows/heist', {
    'GET /api/auth/me': signedInAs('GAME_MANAGER'),
    [`GET ${base}`]: json(draft),
    [`GET ${base}/narrative`]: json(narrativeFlows),
    ...handlers,
  })
  const form = await screen.findByRole('form', { name: 'Flow' })
  const outline = within(screen.getByRole('navigation', { name: 'Flow outline' }))
  // Every phase has a World turn hook of its own: take the first phase's.
  const [worldTurn] = outline.getAllByRole('button', { name: 'World turn (0 steps)' })
  if (!worldTurn) {
    throw new Error('No World turn hook.')
  }
  await user.click(worldTurn)
  await user.click(within(form).getByRole('button', { name: 'Add step' }))
  await user.click(await screen.findByRole('menuitem', { name: 'table' }))
  openStepCards()
  return { user, router, form: within(form) }
}

describe('New table from a flow step', () => {
  it('creates a table for a table step, selects it and saves with the adopted version', async () => {
    const saved: { expectedVersion: number; item: { phases: Record<string, unknown>[] } }[] = []
    const { user, form } = await openTableStep({
      [`POST ${base}/oracle-tables`]: json({ ...oracles, version: 6 }),
      [`PUT ${base}/narrative/flows/heist`]: async (request) => {
        saved.push((await request.json()) as (typeof saved)[number])
        return Response.json(narrativeFlows)
      },
    })

    await user.click(form.getByRole('button', { name: 'New table' }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Key'), 'omens')
    await user.type(within(dialog).getByLabelText('Name'), 'Omens')
    await user.click(within(dialog).getByRole('button', { name: 'Create table' }))

    expect(await screen.findByRole('link', { name: /Omens/ })).toHaveAttribute(
      'href',
      '/studio/game-systems/my-draft/oracle-tables/omens',
    )
    await user.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(saved).toHaveLength(1)
    })
    expect(saved[0]?.expectedVersion).toBe(6)
    expect(saved[0]?.item.phases[0]?.worldTurn).toMatchObject([{ kind: 'table', table: 'omens' }])
  })

  it("is not offered in a Library pack, which keeps today's pickers", async () => {
    await openTableStep({ [`GET ${base}`]: json({ ...packSystem, key: 'my-draft' }) })
    await screen.findByText(/Fantasy basics/)

    expect(screen.queryAllByRole('button', { name: 'New table' })).toHaveLength(0)
  })

  it('on a conflict keeps the step and shows the editor reload', async () => {
    const { user, form } = await openTableStep({
      [`POST ${base}/oracle-tables`]: json(changed, 409),
    })

    await user.click(form.getByRole('button', { name: 'New table' }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Key'), 'omens')
    await user.type(within(dialog).getByLabelText('Name'), 'Omens')
    await user.click(within(dialog).getByRole('button', { name: 'Create table' }))

    expect(await within(dialog).findByText(/was changed by another request/)).toBeInTheDocument()
    expect(
      screen.getByRole('button', { name: 'Reload the latest version', hidden: true }),
    ).toBeInTheDocument()
    expect(screen.getByRole('group', { name: 'Step 1', hidden: true })).toBeInTheDocument()
  })

  it('blocks the link to the new table behind the unsaved-changes dialog while the step is dirty', async () => {
    const { user, router, form } = await openTableStep({
      [`POST ${base}/oracle-tables`]: json({ ...oracles, version: 6 }),
    })

    await user.click(form.getByRole('button', { name: 'New table' }))
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Key'), 'omens')
    await user.type(within(dialog).getByLabelText('Name'), 'Omens')
    await user.click(within(dialog).getByRole('button', { name: 'Create table' }))
    await user.click(await screen.findByRole('link', { name: /Omens/ }))

    expect(await screen.findByText('Leave without saving?')).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft/flows/heist')
  })
})
