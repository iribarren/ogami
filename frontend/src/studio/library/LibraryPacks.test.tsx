import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { catalog, emptyPack, myPack, otherPack, othersCatalog, packSystem } from './fixtures'
import { typeText } from '@/test/typeText'

const gameManager = signedInAs('GAME_MANAGER')

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

function renderHome(handlers: Record<string, FakeHandler> = {}) {
  return renderAppAt('/studio', {
    'GET /api/auth/me': gameManager,
    'GET /api/studio/game-systems': json([]),
    'GET /api/studio/library/packs': json([myPack, otherPack, emptyPack]),
    ...handlers,
  })
}

describe('the Library section of the Studio home', () => {
  it('lists every pack with its counts, marks mine and links each to its page', async () => {
    renderHome()

    const region = await screen.findByRole('region', { name: 'Library' })
    await within(region).findAllByRole('link')
    const card = (name: string) => within(region).getByRole('link', { name }).closest('li')
    const [mine, others, empty] = ['Fantasy basics', 'Wild tales', 'Blank pack'].map(card)
    expect(mine).toHaveTextContent('Fantasy basics')
    expect(mine).toHaveTextContent('fantasy-basics')
    expect(mine).toHaveTextContent('Names, rumours and a tavern.')
    expect(mine).toHaveTextContent('Yours')
    expect(mine).toHaveTextContent('Oracle tables 2')
    expect(mine).toHaveTextContent('Tags 3')
    expect(mine).not.toHaveTextContent('Likelihood oracles')
    expect(others).not.toHaveTextContent('Yours')
    expect(others).toHaveTextContent('Likelihood oracles 1')
    expect(empty).toHaveTextContent('No items yet')
    // Mine opens for editing, the others as a read-only catalog.
    expect(within(region).getByRole('link', { name: 'Fantasy basics' })).toHaveAttribute(
      'href',
      '/studio/game-systems/fantasy-basics',
    )
    expect(within(region).getByRole('link', { name: 'Wild tales' })).toHaveAttribute(
      'href',
      '/studio/library/wild-tales',
    )
  })

  it('says when the library has no pack yet', async () => {
    renderHome({ 'GET /api/studio/library/packs': json([]) })

    expect(
      await within(await screen.findByRole('region', { name: 'Library' })).findByText(
        /The library has no packs yet/,
      ),
    ).toBeInTheDocument()
  })

  it('shows why the packs could not be loaded', async () => {
    renderHome({ 'GET /api/studio/library/packs': () => new Response('Oops', { status: 500 }) })

    expect(
      await within(await screen.findByRole('region', { name: 'Library' })).findByRole(
        'alert',
        {},
        { timeout: 3000 },
      ),
    ).toHaveTextContent('Loading the library packs failed with HTTP 500.')
  })
})

describe('the New pack dialog', () => {
  async function openDialog(handlers: Record<string, FakeHandler>) {
    const user = userEvent.setup()
    const rendered = renderHome(handlers)
    await user.click(await screen.findByRole('button', { name: 'New pack' }))
    const form = await screen.findByRole('form', { name: 'New library pack' })
    return { user, ...rendered, form: within(form) }
  }

  it('creates a pack, refreshes the packs and opens it for editing', async () => {
    const sent: unknown[] = []
    const { user, router, fakeApi, form } = await openDialog({
      'POST /api/studio/library/packs': async (request) => {
        sent.push(await request.json())
        return Response.json(packSystem, { status: 201 })
      },
      'GET /api/studio/game-systems/fantasy-basics': json(packSystem),
      'GET /api/studio/game-systems/fantasy-basics/validation': json({
        valid: true,
        errors: [],
        warnings: [],
      }),
    })

    const create = form.getByRole('button', { name: 'Create pack' })
    expect(create).toBeDisabled()
    await typeText(user, form.getByLabelText('Key'), 'fantasy-basics')
    await typeText(user, form.getByLabelText('Name'), 'Fantasy basics')
    await typeText(
      user,
      form.getByLabelText('Description (optional)'),
      'Names, rumours and a tavern.',
    )
    await user.click(create)

    await waitFor(() => {
      expect(router.state.location.pathname).toBe('/studio/game-systems/fantasy-basics')
    })
    expect(sent).toEqual([
      {
        key: 'fantasy-basics',
        name: 'Fantasy basics',
        description: 'Names, rumours and a tavern.',
      },
    ])
    expect(fakeApi.requests.filter((r) => r === 'GET /api/studio/library/packs')).toHaveLength(2)
  })

  it('leaves the description out when it is blank', async () => {
    const sent: unknown[] = []
    const { user, form } = await openDialog({
      'POST /api/studio/library/packs': async (request) => {
        sent.push(await request.json())
        return Response.json({ error: 'stop' }, { status: 409 })
      },
    })

    await typeText(user, form.getByLabelText('Key'), 'fantasy-basics')
    await typeText(user, form.getByLabelText('Name'), 'Fantasy basics')
    await user.click(form.getByRole('button', { name: 'Create pack' }))

    await waitFor(() => {
      expect(sent).toEqual([{ key: 'fantasy-basics', name: 'Fantasy basics' }])
    })
  })

  it.each([
    [409, 'There is already a GameSystem with the key "fantasy-basics".'],
    [422, 'The key must be lower case letters, digits and hyphens.'],
  ])('shows the API message of a %i refusal and stays on the dialog', async (status, message) => {
    const { user, router, form } = await openDialog({
      'POST /api/studio/library/packs': json({ error: message }, status),
    })

    await typeText(user, form.getByLabelText('Key'), 'fantasy-basics')
    await typeText(user, form.getByLabelText('Name'), 'Fantasy basics')
    await user.click(form.getByRole('button', { name: 'Create pack' }))

    expect(await form.findByRole('alert')).toHaveTextContent(message)
    expect(router.state.location.pathname).toBe('/studio')
    expect(form.getByRole('button', { name: 'Create pack' })).toBeEnabled()
  })
})

describe('the library pack page', () => {
  function renderCatalog(pack: string, handlers: Record<string, FakeHandler>) {
    return renderAppAt(`/studio/library/${pack}`, {
      'GET /api/auth/me': gameManager,
      'GET /api/studio/game-systems': json([]),
      'GET /api/studio/library/packs': json([]),
      ...handlers,
    })
  }

  it('shows the items by section with what they use, and links my own pack to its editor', async () => {
    renderCatalog('fantasy-basics', {
      'GET /api/studio/library/packs/fantasy-basics': json(catalog),
    })

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Fantasy basics' }),
    ).toBeInTheDocument()
    expect(screen.getByText('Yours')).toBeInTheDocument()
    expect(screen.getByText('Names, rumours and a tavern.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Edit this pack' })).toHaveAttribute(
      'href',
      '/studio/game-systems/fantasy-basics',
    )
    const oracles = within(screen.getByRole('region', { name: 'Oracles' })).getAllByRole('listitem')
    expect(oracles[0]).toHaveTextContent('Names')
    expect(oracles[0]).toHaveTextContent('Table')
    expect(oracles[1]).toHaveTextContent('Mood')
    expect(oracles[1]).toHaveTextContent('Likelihood')
    expect(
      within(screen.getByRole('region', { name: 'Fact slots' })).getByText('No items.'),
    ).toBeInTheDocument()
    const tavern = within(screen.getByRole('region', { name: 'Scene Types' })).getByRole('listitem')
    expect(tavern).toHaveTextContent('Tavern')
    expect(tavern).toHaveTextContent('Uses: names, social')
    expect(within(tavern).queryByRole('button')).not.toBeInTheDocument()
  })

  it("is read only for someone else's pack, with no link to edit it", async () => {
    renderCatalog('wild-tales', {
      'GET /api/studio/library/packs/wild-tales': json(othersCatalog),
    })

    expect(await screen.findByRole('heading', { level: 1, name: 'Wild tales' })).toBeInTheDocument()
    expect(screen.queryByText('Yours')).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Edit this pack' })).not.toBeInTheDocument()
    // Only the app shell's own button is there: nothing to edit.
    expect(screen.getAllByRole('button').map((button) => button.textContent)).toEqual(['Sign out'])
  })

  it('shows a not-found state for an unknown pack', async () => {
    renderCatalog('nobody', {
      'GET /api/studio/library/packs/nobody': json({ error: 'No such pack.' }, 404),
    })

    expect(
      await screen.findByRole('heading', { level: 1, name: 'No such pack' }),
    ).toBeInTheDocument()
  })

  it('shows why the pack could not be loaded', async () => {
    renderCatalog('wild-tales', {
      'GET /api/studio/library/packs/wild-tales': () => new Response('Oops', { status: 500 }),
    })

    expect(await screen.findByRole('alert', {}, { timeout: 3000 })).toHaveTextContent(
      'Loading the library pack failed with HTTP 500.',
    )
  })
})
