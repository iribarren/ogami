import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { openStepCards } from '@/test/openStepCards'
import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { draft, narrativeScenes, oracles } from '../../fixtures'
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
const create = `POST ${base}/oracle-tables`
const put = `PUT ${base}/narrative/scene-types/ambush`
const changed = {
  error: 'GameSystem "my-draft" was changed by another request. Reload it and try again.',
}

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

async function openEditor(handlers: Record<string, FakeHandler> = {}, offered = true) {
  const user = userEvent.setup()
  const rendered = renderAppAt('/studio/game-systems/my-draft/scene-types/ambush', {
    'GET /api/auth/me': signedInAs('GAME_MANAGER'),
    [`GET ${base}`]: json(draft),
    [`GET ${base}/narrative`]: json(narrativeScenes),
    ...handlers,
  })
  await screen.findByRole('form', { name: 'Scene Type' })
  openStepCards()
  if (offered) {
    // The button waits for the GameSystem to say it is not a Library pack.
    await within(screen.getByRole('region', { name: 'Setup' })).findByRole('button', {
      name: 'New table',
    })
  }
  return { user, ...rendered }
}

/** The "New table" button of the setup list's prompt step, the only prompt in it. */
const newTableButton = () =>
  within(screen.getByRole('region', { name: 'Setup' })).getByRole('button', { name: 'New table' })

async function createTable(user: ReturnType<typeof userEvent.setup>, key: string, name: string) {
  await user.click(newTableButton())
  const dialog = await screen.findByRole('dialog')
  await user.type(within(dialog).getByLabelText('Key'), key)
  await user.type(within(dialog).getByLabelText('Name'), name)
  await user.click(within(dialog).getByRole('button', { name: 'Create table' }))
  return dialog
}

async function body(request: Request) {
  return (await request.json()) as {
    expectedVersion: number
    table?: { key: string; name: string; entries: { weight?: number }[] }
    item?: { setup: { inspiration?: string[] }[] }
  }
}

describe('New table from a step', () => {
  it('is offered under the Inspiration tables of a prompt step', async () => {
    await openEditor()

    expect(newTableButton()).toBeEnabled()
  })

  it("is not offered in a Library pack, which keeps today's pickers", async () => {
    await openEditor({ [`GET ${base}`]: json({ ...packSystem, key: 'my-draft' }) }, false)
    await screen.findByText(/Fantasy basics/)

    expect(screen.queryAllByRole('button', { name: 'New table' })).toHaveLength(0)
  })

  it('blocks the link to the new table behind the unsaved-changes dialog while the step is dirty', async () => {
    const { user, router } = await openEditor({ [create]: json({ ...oracles, version: 6 }) })
    await user.clear(screen.getByLabelText('Name'))
    await user.type(screen.getByLabelText('Name'), 'Mine')
    await createTable(user, 'omens', 'Omens')

    await user.click(await screen.findByRole('link', { name: /Omens/ }))

    expect(await screen.findByText('Leave without saving?')).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft/scene-types/ambush')
  })

  it('creates a weighted table at the editor version, selects it and links to it', async () => {
    const sent: Awaited<ReturnType<typeof body>>[] = []
    const { user } = await openEditor({
      [create]: async (request) => {
        sent.push(await body(request))
        return Response.json({ ...oracles, version: 6 })
      },
    })

    await createTable(user, 'omens', 'Omens')

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
    expect(sent).toHaveLength(1)
    expect(sent[0]?.expectedVersion).toBe(5)
    expect(sent[0]?.table).toMatchObject({ key: 'omens', name: 'Omens' })
    expect(sent[0]?.table?.entries).toHaveLength(1)
    expect(sent[0]?.table?.entries[0]?.weight).toBe(1)
    expect(await screen.findByRole('checkbox', { name: /omens/i })).toBeChecked()
    expect(screen.getByRole('link', { name: /Omens/ })).toHaveAttribute(
      'href',
      '/studio/game-systems/my-draft/oracle-tables/omens',
    )
  })

  it('keeps unsaved step edits and saves them with the version the create answered', async () => {
    const saved: Awaited<ReturnType<typeof body>>[] = []
    const { user } = await openEditor({
      [create]: json({ ...oracles, version: 6 }),
      [put]: async (request) => {
        saved.push(await body(request))
        return Response.json({ ...narrativeScenes, version: 7 })
      },
    })
    await user.clear(screen.getByLabelText('Name'))
    await user.type(screen.getByLabelText('Name'), 'Mine')

    await createTable(user, 'omens', 'Omens')
    await screen.findByRole('link', { name: /Omens/ })
    expect(screen.getByLabelText('Name')).toHaveValue('Mine')
    await user.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(saved).toHaveLength(1)
    })
    expect(saved[0]?.expectedVersion).toBe(6)
    expect(saved[0]?.item?.setup[0]?.inspiration).toEqual(['omens'])
  })

  it('does not save the step when Enter submits the new table form', async () => {
    let puts = 0
    const { user } = await openEditor({
      [create]: json({ ...oracles, version: 6 }),
      [put]: () => {
        puts += 1
        return Response.json(narrativeScenes)
      },
    })

    await user.click(newTableButton())
    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText('Key'), 'omens')
    await user.type(within(dialog).getByLabelText('Name'), 'Omens{Enter}')

    await screen.findByRole('link', { name: /Omens/ })
    expect(puts).toBe(0)
  })

  it('on a conflict keeps the draft, selects nothing and offers the editor reload', async () => {
    const { user } = await openEditor({ [create]: json(changed, 409) })
    await user.clear(screen.getByLabelText('Name'))
    await user.type(screen.getByLabelText('Name'), 'Mine')

    const dialog = await createTable(user, 'omens', 'Omens')

    expect(await within(dialog).findByText(/was changed by another request/)).toBeInTheDocument()
    // The dialog hides the editor from the accessibility tree while it is open.
    const editor = within(screen.getByRole('form', { name: 'Scene Type', hidden: true }))
    expect(editor.getByLabelText('Name')).toHaveValue('Mine')
    expect(editor.queryByRole('checkbox', { name: /omens/i, hidden: true })).not.toBeInTheDocument()
    expect(
      editor.getByRole('button', { name: 'Reload the latest version', hidden: true }),
    ).toBeInTheDocument()
  })

  it('shows a refused key at the form and keeps the step', async () => {
    const { user } = await openEditor({
      [create]: json({ error: 'The key "omens" is already used by a table.' }, 422),
    })

    const dialog = await createTable(user, 'omens', 'Omens')

    expect(await within(dialog).findByRole('alert')).toHaveTextContent('already used by a table')
    expect(within(dialog).getByLabelText('Key')).toHaveValue('omens')
    expect(screen.queryByRole('link', { name: /Omens/ })).not.toBeInTheDocument()
  })

  it('shows any other failure at the form', async () => {
    const { user } = await openEditor({ [create]: json({}, 500) })

    const dialog = await createTable(user, 'omens', 'Omens')

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'Creating the table failed with HTTP 500.',
    )
  })
})
