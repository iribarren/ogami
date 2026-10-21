import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { draft, legacy, validDraft } from '../fixtures'
import { importsMixed, importsNone, packSystem } from './fixtures'

const base = '/api/studio/game-systems/my-draft'

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

async function openLibrary(handlers: Record<string, FakeHandler> = {}) {
  const user = userEvent.setup()
  const rendered = renderAppAt('/studio/game-systems/my-draft', {
    'GET /api/auth/me': signedInAs('GAME_MANAGER'),
    'GET /api/studio/game-systems': json([]),
    [`GET ${base}`]: json(draft),
    [`GET ${base}/validation`]: json(validDraft),
    [`GET ${base}/library-imports`]: json(importsMixed),
    ...handlers,
  })
  await user.click(await screen.findByRole('link', { name: 'Library' }))
  return { user, ...rendered }
}

const row = async (name: string) => (await screen.findByText(name)).closest('li') as HTMLElement
const stale = {
  error: 'GameSystem "my-draft" was changed by another request. Reload it and try again.',
}

/** The imports without one item, at the next version. */
const without = (key: string) => ({
  version: 5,
  imports: importsMixed.imports.filter((item) => item.key !== key),
})

describe('the Library tab', () => {
  it('lists the imports by section with their pack, status and local edits', async () => {
    await openLibrary()

    const oracles = within(await screen.findByRole('region', { name: 'Oracles' }))
    expect(oracles.getByRole('listitem')).toHaveTextContent('Names')
    expect(oracles.getByRole('listitem')).toHaveTextContent('From Fantasy basics')
    expect(oracles.getByRole('listitem')).toHaveTextContent('Current')
    const tavern = await row('Tavern')
    expect(tavern).toHaveTextContent('Changed')
    expect(tavern).toHaveTextContent('Edited locally')
    expect(await row('Social')).not.toHaveTextContent('Edited locally')
    const fame = await row('Fame')
    expect(fame).toHaveTextContent('Removed')
    expect(fame).toHaveTextContent('Removed pack')
    expect(
      within(screen.getByRole('region', { name: 'Library' })).getByRole('heading', {
        name: 'Scene Types',
      }),
    ).toBeInTheDocument()
  })

  it('enables Update only for a changed item and Detach for every one, a removed item included', async () => {
    await openLibrary()
    await row('Fame')

    expect(screen.getByRole('button', { name: 'Update names' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Update fame' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Update social' })).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Detach fame' })).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Detach names' })).toBeEnabled()
  })

  it('updates a changed item with the version it read, then shows the answer', async () => {
    const sent: unknown[] = []
    const { user } = await openLibrary({
      [`POST ${base}/library-imports/tags/social/update`]: async (request) => {
        sent.push(await request.json())
        return Response.json(without('social'))
      },
    })

    await user.click(await screen.findByRole('button', { name: 'Update social' }))

    await waitFor(() => {
      expect(screen.queryByText('Social')).not.toBeInTheDocument()
    })
    expect(sent).toEqual([{ expectedVersion: 4 }])
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('asks before an update replaces local edits, and sends nothing when it is declined', async () => {
    const sent: unknown[] = []
    const { user } = await openLibrary({
      [`POST ${base}/library-imports/scene-types/tavern/update`]: async (request) => {
        sent.push(await request.json())
        return Response.json(without('tavern'))
      },
    })

    await user.click(await screen.findByRole('button', { name: 'Update tavern' }))
    expect(within(screen.getByRole('dialog')).getByText(/replaces your local edits/)).toBeVisible()
    await user.keyboard('{Escape}')
    expect(sent).toEqual([])

    await user.click(screen.getByRole('button', { name: 'Update tavern' }))
    await user.click(
      within(screen.getByRole('dialog')).getByRole('button', { name: 'Replace my edits' }),
    )

    await waitFor(() => {
      expect(sent).toEqual([{ expectedVersion: 4 }])
    })
  })

  it('detaches an item after a confirmation, with the version as a query parameter', async () => {
    const urls: string[] = []
    const { user } = await openLibrary({
      [`DELETE ${base}/library-imports/trackers/fame`]: (request) => {
        urls.push(new URL(request.url).search)
        return Response.json(without('fame'))
      },
    })

    await user.click(await screen.findByRole('button', { name: 'Detach fame' }))
    expect(within(screen.getByRole('dialog')).getByText(/no longer follows/)).toBeVisible()
    await user.click(
      within(screen.getByRole('dialog')).getByRole('button', { name: 'Detach the item' }),
    )

    await waitFor(() => {
      expect(screen.queryByText('Fame')).not.toBeInTheDocument()
    })
    expect(urls).toEqual(['?expectedVersion=4'])
  })

  it('refreshes the draft and the next write uses the new version', async () => {
    let drafts = 0
    const sent: unknown[] = []
    const { user } = await openLibrary({
      [`GET ${base}`]: () => {
        drafts += 1
        return Response.json(draft)
      },
      [`POST ${base}/library-imports/tags/social/update`]: () => Response.json(without('social')),
      [`DELETE ${base}/library-imports/trackers/fame`]: (request) => {
        sent.push(new URL(request.url).search)
        return Response.json(without('fame'))
      },
    })
    await user.click(await screen.findByRole('button', { name: 'Update social' }))
    await waitFor(() => {
      expect(drafts).toBe(2)
    })

    await user.click(screen.getByRole('button', { name: 'Detach fame' }))
    await user.click(
      within(screen.getByRole('dialog')).getByRole('button', { name: 'Detach the item' }),
    )

    await waitFor(() => {
      expect(sent).toEqual(['?expectedVersion=5'])
    })
  })

  it('disables every action while one is running', async () => {
    const { user } = await openLibrary({
      [`POST ${base}/library-imports/tags/social/update`]: () =>
        new Promise<Response>(() => undefined),
    })

    await user.click(await screen.findByRole('button', { name: 'Update social' }))

    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Update social' })).toBeDisabled()
    })
    expect(screen.getByRole('button', { name: 'Detach fame' })).toBeDisabled()
  })

  it('offers to reload the latest version after a 409, and then shows the new list', async () => {
    let reads = 0
    const { user } = await openLibrary({
      [`GET ${base}/library-imports`]: () =>
        Response.json(++reads === 1 ? importsMixed : { ...without('social'), version: 6 }),
      [`POST ${base}/library-imports/tags/social/update`]: json(stale, 409),
    })

    await user.click(await screen.findByRole('button', { name: 'Update social' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('was changed by another request')
    await user.click(screen.getByRole('button', { name: 'Reload the latest version' }))

    await waitFor(() => {
      expect(screen.queryByText('Social')).not.toBeInTheDocument()
    })
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('offers the reload only for a stale write, not for another 409', async () => {
    const { user } = await openLibrary({
      [`POST ${base}/library-imports/tags/social/update`]: json(
        { error: 'The pack or its item is gone.' },
        409,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'Update social' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('The pack or its item is gone.')
    expect(
      screen.queryByRole('button', { name: 'Reload the latest version' }),
    ).not.toBeInTheDocument()
  })

  it('keeps the refusal visible when the reload fails', async () => {
    let reads = 0
    const { user } = await openLibrary({
      [`GET ${base}/library-imports`]: () =>
        ++reads === 1 ? Response.json(importsMixed) : new Response('Oops', { status: 500 }),
      [`POST ${base}/library-imports/tags/social/update`]: json(stale, 409),
    })
    await user.click(await screen.findByRole('button', { name: 'Update social' }))
    await screen.findByText(/was changed by another request/)

    await user.click(screen.getByRole('button', { name: 'Reload the latest version' }))

    expect(
      await screen.findByText(/Couldn't refresh the library imports/, {}, { timeout: 3000 }),
    ).toBeVisible()
    expect(screen.getByText(/was changed by another request/)).toBeVisible()
    expect(screen.getByRole('button', { name: 'Reload the latest version' })).toBeVisible()
  })

  it('does not refetch the imports after a write: it is set from the answer', async () => {
    let drafts = 0
    let imports = 0
    const { user } = await openLibrary({
      [`GET ${base}`]: () => {
        drafts += 1
        return Response.json(draft)
      },
      [`GET ${base}/library-imports`]: () => {
        imports += 1
        return Response.json(importsMixed)
      },
      [`POST ${base}/library-imports/tags/social/update`]: () => Response.json(without('social')),
    })
    await user.click(await screen.findByRole('button', { name: 'Update social' }))

    await waitFor(() => {
      expect(drafts).toBe(2)
    })
    await waitFor(() => {
      expect(screen.queryByText('Social')).not.toBeInTheDocument()
    })
    expect(imports).toBe(1)
  })

  it.each([
    [404, 'The draft did not import this item.'],
    [409, 'The pack or its item is gone.'],
    [422, 'sceneTypes[0]: the result breaks a rule.'],
  ])('shows the API reason of a %i inline', async (status, error) => {
    const { user } = await openLibrary({
      [`POST ${base}/library-imports/tags/social/update`]: json({ error }, status),
    })

    await user.click(await screen.findByRole('button', { name: 'Update social' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(error)
    // The list is unchanged.
    expect(await row('Social')).toBeInTheDocument()
  })

  it('shows a generic failure when the API gives no reason', async () => {
    const { user } = await openLibrary({
      [`DELETE ${base}/library-imports/trackers/fame`]: () => new Response('Oops', { status: 500 }),
    })

    await user.click(await screen.findByRole('button', { name: 'Detach fame' }))
    await user.click(
      within(screen.getByRole('dialog')).getByRole('button', { name: 'Detach the item' }),
    )

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Detaching the item failed with HTTP 500.',
    )
  })

  it('says what is empty and offers the import, here and in the header', async () => {
    await openLibrary({ [`GET ${base}/library-imports`]: json(importsNone) })

    expect(await screen.findByText(/has imported nothing from the library yet/)).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: 'Import from library' })).toHaveLength(2)
  })

  it('shows why the imports could not be loaded', async () => {
    await openLibrary({
      [`GET ${base}/library-imports`]: () => new Response('Oops', { status: 500 }),
    })

    expect(await screen.findByRole('alert', {}, { timeout: 3000 })).toHaveTextContent(
      'Loading the library imports failed with HTTP 500.',
    )
  })

  it('shows a notice instead of imports for a schema version 1 draft, and asks for none', async () => {
    const user = userEvent.setup()
    const { fakeApi } = renderAppAt('/studio/game-systems/free-journal', {
      'GET /api/auth/me': signedInAs('GAME_MANAGER'),
      'GET /api/studio/game-systems': json([]),
      'GET /api/studio/game-systems/free-journal': json(legacy),
      'GET /api/studio/game-systems/free-journal/validation': json(validDraft),
    })

    await user.click(await screen.findByRole('link', { name: 'Library' }))

    expect(await screen.findByText(/Schema version 1 drafts import nothing/)).toBeInTheDocument()
    expect(fakeApi.requests.some((request) => request.includes('library-imports'))).toBe(false)
  })

  it('has no Library tab on a library pack', async () => {
    renderAppAt('/studio/game-systems/fantasy-basics', {
      'GET /api/auth/me': signedInAs('GAME_MANAGER'),
      'GET /api/studio/game-systems': json([]),
      'GET /api/studio/game-systems/fantasy-basics': json(packSystem),
      'GET /api/studio/game-systems/fantasy-basics/validation': json(validDraft),
    })

    await screen.findByText('Library pack')
    expect(screen.queryByRole('link', { name: 'Library' })).not.toBeInTheDocument()
  })
})
