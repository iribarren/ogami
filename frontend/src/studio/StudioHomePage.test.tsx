import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { draft, neverPublished, published, withChanges, withLibraryChanges } from './fixtures'
import { replaceText, typeText } from '@/test/typeText'

const gameManager = signedInAs('GAME_MANAGER')

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

function list() {
  return screen.getByRole('region', { name: 'Your GameSystems' })
}

describe('the Studio home page', () => {
  it('lists my GameSystems as cards that link to their page', async () => {
    renderAppAt('/studio', {
      'GET /api/auth/me': gameManager,
      'GET /api/studio/game-systems': json([neverPublished, withChanges, published]),
      'GET /api/studio/library/packs': json([]),
    })

    expect(await screen.findByRole('heading', { level: 1, name: 'Studio' })).toBeInTheDocument()
    const items = await within(list()).findAllByRole('listitem')
    expect(items).toHaveLength(3)
    expect(items[0]).toHaveTextContent('My draft')
    expect(items[0]).toHaveTextContent('my-draft')
    expect(items[0]).toHaveTextContent('Never published')
    expect(items[0]).toHaveTextContent('Unpublished changes')
    expect(items[1]).toHaveTextContent('Latest release v1')
    expect(items[1]).toHaveTextContent('Unpublished changes')
    expect(items[2]).toHaveTextContent('Latest release v3')
    expect(items[2]).not.toHaveTextContent('Unpublished changes')
    expect(within(list()).getByRole('link', { name: 'Noir detective' })).toHaveAttribute(
      'href',
      '/studio/game-systems/noir-detective',
    )
    expect(within(list()).getByText('3')).toHaveClass('count-pill')
  })

  it('warns of the library changes of a draft and says nothing at 0', async () => {
    renderAppAt('/studio', {
      'GET /api/auth/me': gameManager,
      'GET /api/studio/game-systems': json([
        published,
        withLibraryChanges,
        { ...withLibraryChanges, key: 'one', name: 'One', libraryChanges: 1 },
      ]),
    })

    expect(await screen.findByRole('heading', { level: 1, name: 'Studio' })).toBeInTheDocument()
    const items = await within(list()).findAllByRole('listitem')
    expect(items[0]).not.toHaveTextContent('library change')
    expect(items[1]).toHaveTextContent('3 library changes')
    expect(items[2]).toHaveTextContent('1 library change')
    expect(items[2]).not.toHaveTextContent('1 library changes')
  })

  it('says when I have no GameSystem yet', async () => {
    renderAppAt('/studio', {
      'GET /api/auth/me': gameManager,
      'GET /api/studio/game-systems': json([]),
    })

    expect(
      await within(await screen.findByRole('region', { name: 'Your GameSystems' })).findByText(
        'You have no GameSystems yet. Create one to start authoring.',
      ),
    ).toBeInTheDocument()
  })

  it('shows why the GameSystems could not be loaded', async () => {
    renderAppAt('/studio', {
      'GET /api/auth/me': gameManager,
      'GET /api/studio/game-systems': () => new Response('Oops', { status: 500 }),
    })

    expect(
      await within(await screen.findByRole('region', { name: 'Your GameSystems' })).findByRole(
        'alert',
        {},
        { timeout: 3000 },
      ),
    ).toHaveTextContent('Loading your GameSystems failed with HTTP 500.')
  })
})

describe('the New GameSystem dialog', () => {
  async function openDialog(
    handlers: Record<string, FakeHandler>,
    user: ReturnType<typeof userEvent.setup>,
  ) {
    const rendered = renderAppAt('/studio', {
      'GET /api/auth/me': gameManager,
      'GET /api/studio/game-systems': json([]),
      ...handlers,
    })
    await user.click(await screen.findByRole('button', { name: 'New GameSystem' }))
    return { ...rendered, form: await screen.findByRole('form', { name: 'New GameSystem' }) }
  }

  it('closes with the Close button and with Cancel', async () => {
    const user = userEvent.setup()
    await openDialog({}, user)

    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Close' }))
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })

    await user.click(screen.getByRole('button', { name: 'New GameSystem' }))
    await user.click(
      within(await screen.findByRole('form', { name: 'New GameSystem' })).getByRole('button', {
        name: 'Cancel',
      }),
    )
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
  })

  it('creates a blank draft and opens it', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    const { router, fakeApi, form } = await openDialog(
      {
        'POST /api/studio/game-systems': async (request) => {
          sent.push(await request.json())
          return Response.json(draft, { status: 201 })
        },
      },
      user,
    )

    const create = within(form).getByRole('button', { name: 'Create GameSystem' })
    expect(create).toBeDisabled()
    expect(within(form).getByLabelText('Key')).toHaveAccessibleDescription(
      /^Lower case letters, digits and hyphens\./,
    )
    expect(within(form).queryByLabelText('Source GameSystem key')).not.toBeInTheDocument()
    await typeText(user, within(form).getByLabelText('Key'), 'my-draft')
    await typeText(user, within(form).getByLabelText('Name'), 'My draft')
    expect(create).toBeEnabled()
    await user.click(create)

    await waitFor(() => {
      expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft')
    })
    expect(sent).toEqual([{ key: 'my-draft', name: 'My draft' }])
    // The list is refetched, so going back shows the new GameSystem.
    await waitFor(() => {
      expect(fakeApi.requests.filter((r) => r === 'GET /api/studio/game-systems')).toHaveLength(2)
    })
  })

  it('creates a copy of a release, sending the source and the optional description', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    const { router, form } = await openDialog(
      {
        'POST /api/studio/game-systems': async (request) => {
          sent.push(await request.json())
          return Response.json(draft, { status: 201 })
        },
      },
      user,
    )

    await user.click(screen.getByRole('radio', { name: 'Copy a release' }))
    const create = within(form).getByRole('button', { name: 'Create GameSystem' })
    await typeText(user, within(form).getByLabelText('Key'), 'my-draft')
    await typeText(user, within(form).getByLabelText('Name'), 'My draft')
    expect(create).toBeDisabled()
    await typeText(user, within(form).getByLabelText('Source GameSystem key'), 'ironsworn')
    await user.type(within(form).getByLabelText(/Source version/), 'x')
    expect(create).toBeDisabled()
    expect(within(form).getByText('The version is a whole number from 1.')).toBeInTheDocument()
    await replaceText(user, within(form).getByLabelText(/Source version/), '2')
    await typeText(user, within(form).getByLabelText('Description (optional)'), 'My own take.')
    await user.click(create)

    await waitFor(() => {
      expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft')
    })
    expect(sent).toEqual([
      {
        key: 'my-draft',
        name: 'My draft',
        description: 'My own take.',
        source: { key: 'ironsworn', version: 2 },
      },
    ])
  })

  it('copies the latest release when the version is left empty', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    const { form } = await openDialog(
      {
        'POST /api/studio/game-systems': async (request) => {
          sent.push(await request.json())
          return Response.json(draft, { status: 201 })
        },
      },
      user,
    )

    await user.click(screen.getByRole('radio', { name: 'Copy a release' }))
    await typeText(user, within(form).getByLabelText('Key'), 'my-draft')
    await typeText(user, within(form).getByLabelText('Name'), 'My draft')
    await typeText(user, within(form).getByLabelText('Source GameSystem key'), 'ironsworn')
    await user.click(within(form).getByRole('button', { name: 'Create GameSystem' }))

    await waitFor(() => {
      expect(sent).toEqual([{ key: 'my-draft', name: 'My draft', source: { key: 'ironsworn' } }])
    })
  })

  it.each([
    [409, 'There is already a GameSystem with the key "my-draft".'],
    [422, 'The key must be lower case letters, digits and hyphens.'],
    [400, 'Send the GameSystem as JSON.'],
  ])('shows the API message of a %i refusal and stays on the dialog', async (status, message) => {
    const user = userEvent.setup()
    const { router, form } = await openDialog(
      { 'POST /api/studio/game-systems': json({ error: message }, status) },
      user,
    )

    await typeText(user, within(form).getByLabelText('Key'), 'my-draft')
    await typeText(user, within(form).getByLabelText('Name'), 'My draft')
    await user.click(within(form).getByRole('button', { name: 'Create GameSystem' }))

    expect(await within(form).findByRole('alert')).toHaveTextContent(message)
    expect(router.state.location.pathname).toBe('/studio')
    expect(within(form).getByRole('button', { name: 'Create GameSystem' })).toBeEnabled()
  })

  it('shows a generic message when creating fails unexpectedly', async () => {
    const user = userEvent.setup()
    const { form } = await openDialog(
      { 'POST /api/studio/game-systems': () => new Response('Oops', { status: 500 }) },
      user,
    )

    await typeText(user, within(form).getByLabelText('Key'), 'my-draft')
    await typeText(user, within(form).getByLabelText('Name'), 'My draft')
    await user.click(within(form).getByRole('button', { name: 'Create GameSystem' }))

    expect(await within(form).findByRole('alert')).toHaveTextContent(
      'Creating the GameSystem failed with HTTP 500.',
    )
  })
})
