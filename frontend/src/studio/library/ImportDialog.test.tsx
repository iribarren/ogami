import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { draft, validDraft } from '../fixtures'
import {
  catalog,
  importsMixed,
  importsNone,
  myPack,
  otherPack,
  previewClean,
  previewConflict,
  previewError,
  previewWithOverride,
} from './fixtures'

const base = '/api/studio/game-systems/my-draft'

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

async function openDialog(handlers: Record<string, FakeHandler> = {}) {
  const user = userEvent.setup()
  const rendered = renderAppAt('/studio/game-systems/my-draft', {
    'GET /api/auth/me': signedInAs('GAME_MANAGER'),
    'GET /api/studio/game-systems': json([]),
    [`GET ${base}`]: json(draft),
    [`GET ${base}/validation`]: json(validDraft),
    [`GET ${base}/library-imports`]: json(importsNone),
    'GET /api/studio/library/packs': json([myPack, otherPack]),
    'GET /api/studio/library/packs/fantasy-basics': json(catalog),
    ...handlers,
  })
  await user.click(await screen.findByRole('radio', { name: 'Library' }))
  const [header] = await screen.findAllByRole('button', { name: 'Import from library' })
  if (!header) {
    throw new Error('The Library tab has no import button.')
  }
  await user.click(header)
  return { user, ...rendered, dialog: within(await screen.findByRole('dialog')) }
}

/** Picks the pack and ticks the Scene Type, which needs an oracle and a tag. */
async function pickTavern(user: ReturnType<typeof userEvent.setup>) {
  const dialog = within(screen.getByRole('dialog'))
  await user.click(await dialog.findByRole('button', { name: 'Choose Fantasy basics' }))
  await user.click(await dialog.findByRole('checkbox', { name: /Tavern/ }))
}

const stale = {
  error: 'GameSystem "my-draft" was changed by another request. Reload it and try again.',
}

describe('the Import dialog', () => {
  it('lists the packs as returned, mine marked, and the catalog of the chosen one by section', async () => {
    const { user, dialog } = await openDialog()

    const [mine, others] = await dialog.findAllByRole('listitem')
    expect(mine).toHaveTextContent('Fantasy basics')
    expect(mine).toHaveTextContent('Yours')
    expect(others).toHaveTextContent('Wild tales')
    expect(others).not.toHaveTextContent('Yours')

    await user.click(dialog.getByRole('button', { name: 'Choose Fantasy basics' }))

    expect(await dialog.findByRole('group', { name: 'Scene Types' })).toBeVisible()
    expect(dialog.getByRole('group', { name: 'Oracles' })).toHaveTextContent('Likelihood')
    expect(dialog.getByRole('checkbox', { name: /Tavern/ })).toHaveAccessibleName(
      /Also brings names, social/,
    )
    // The empty section is not listed.
    expect(dialog.queryByRole('group', { name: 'Fact slots' })).not.toBeInTheDocument()
  })

  it('goes back to the packs', async () => {
    const { user, dialog } = await openDialog()
    await user.click(await dialog.findByRole('button', { name: 'Choose Fantasy basics' }))

    await user.click(await dialog.findByRole('button', { name: 'Choose another pack' }))

    expect(await dialog.findByRole('button', { name: 'Choose Wild tales' })).toBeVisible()
  })

  it('previews the selection, then imports it with the draft version and closes', async () => {
    const previews: unknown[] = []
    const imports: unknown[] = []
    const afterImport = {
      version: 5,
      imports: [
        ...importsMixed.imports,
        {
          section: 'scene-types',
          key: 'brawl',
          label: 'Brawl',
          pack: { key: 'fantasy-basics', name: 'Fantasy basics' },
          status: 'current',
          editedLocally: false,
        },
      ],
    }
    const { user, dialog } = await openDialog({
      [`POST ${base}/library-imports/preview`]: async (request) => {
        previews.push(await request.json())
        return Response.json(previewClean)
      },
      [`POST ${base}/library-imports`]: async (request) => {
        imports.push(await request.json())
        return Response.json(afterImport)
      },
    })
    await pickTavern(user)
    expect(dialog.getByRole('button', { name: 'Import' })).toBeDisabled()

    await user.click(dialog.getByRole('button', { name: 'Preview' }))

    const preview = within(await dialog.findByRole('region', { name: 'Preview' }))
    expect(preview.getByText('Will be imported')).toBeVisible()
    expect(preview.getAllByRole('listitem')).toHaveLength(3)
    expect(previews).toEqual([
      { pack: 'fantasy-basics', items: [{ section: 'scene-types', key: 'tavern' }] },
    ])
    await user.click(dialog.getByRole('button', { name: 'Import' }))

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
    expect(imports).toEqual([
      {
        expectedVersion: 4,
        pack: 'fantasy-basics',
        items: [{ section: 'scene-types', key: 'tavern' }],
      },
    ])
    expect(await screen.findByText('Brawl')).toBeInTheDocument()
  })

  it('shows what the draft keeps, and still allows the import', async () => {
    const { user, dialog } = await openDialog({
      [`POST ${base}/library-imports/preview`]: json(previewWithOverride),
    })
    await pickTavern(user)

    await user.click(dialog.getByRole('button', { name: 'Preview' }))

    const preview = within(await dialog.findByRole('region', { name: 'Preview' }))
    expect(preview.getByText('Kept from your draft')).toBeVisible()
    expect(preview.getByText('social')).toBeVisible()
    expect(dialog.getByRole('button', { name: 'Import' })).toBeEnabled()
  })

  it('blocks the import while the draft already has a requested item', async () => {
    const { user, dialog } = await openDialog({
      [`POST ${base}/library-imports/preview`]: json(previewConflict),
    })
    await pickTavern(user)

    await user.click(dialog.getByRole('button', { name: 'Preview' }))

    expect(await dialog.findByText('Already in your draft')).toBeVisible()
    expect(dialog.getByRole('button', { name: 'Import' })).toBeDisabled()
  })

  it('blocks the import while the result would break an item rule, and says where', async () => {
    const { user, dialog } = await openDialog({
      [`POST ${base}/library-imports/preview`]: json(previewError),
    })
    await pickTavern(user)

    await user.click(dialog.getByRole('button', { name: 'Preview' }))

    expect(await dialog.findByRole('alert')).toHaveTextContent(
      'sceneTypes[0].closing[0]: tracker "fame" is not a tracker of the draft',
    )
    expect(dialog.getByRole('button', { name: 'Import' })).toBeDisabled()
  })

  it('forgets the preview when the selection changes, and needs a new one to import', async () => {
    const { user, dialog } = await openDialog({
      [`POST ${base}/library-imports/preview`]: json(previewClean),
    })
    await pickTavern(user)
    await user.click(dialog.getByRole('button', { name: 'Preview' }))
    await dialog.findByRole('region', { name: 'Preview' })

    await user.click(dialog.getByRole('checkbox', { name: /Fame/ }))

    expect(dialog.queryByRole('region', { name: 'Preview' })).not.toBeInTheDocument()
    expect(dialog.getByRole('button', { name: 'Import' })).toBeDisabled()
    await user.click(dialog.getByRole('checkbox', { name: /Fame/ }))
    await user.click(dialog.getByRole('checkbox', { name: /Tavern/ }))
    expect(dialog.getByRole('button', { name: 'Preview' })).toBeDisabled()
  })

  it.each([
    [404, 'The pack has no item "tavern".'],
    [409, 'The draft is a library pack.'],
    [422, 'The items must be a non-empty list.'],
  ])('shows the API reason of a %i on the preview', async (status, error) => {
    const { user, dialog } = await openDialog({
      [`POST ${base}/library-imports/preview`]: json({ error }, status),
    })
    await pickTavern(user)

    await user.click(dialog.getByRole('button', { name: 'Preview' }))

    expect(await dialog.findByRole('alert')).toHaveTextContent(error)
    expect(dialog.getByRole('button', { name: 'Import' })).toBeDisabled()
  })

  it.each([
    [404, 'The pack has no item "tavern".'],
    [409, 'The draft already has these items: scene-types/tavern.'],
    [422, 'sceneTypes[0].closing[0]: a rule is broken.'],
  ])(
    'keeps the dialog open and shows the API reason of a %i on the import',
    async (status, error) => {
      const { user, dialog } = await openDialog({
        [`POST ${base}/library-imports/preview`]: json(previewClean),
        [`POST ${base}/library-imports`]: json({ error }, status),
      })
      await pickTavern(user)
      await user.click(dialog.getByRole('button', { name: 'Preview' }))
      await dialog.findByRole('region', { name: 'Preview' })

      await user.click(dialog.getByRole('button', { name: 'Import' }))

      expect(await dialog.findByRole('alert')).toHaveTextContent(error)
      expect(screen.getByRole('dialog')).toBeVisible()
    },
  )

  it('reloads the latest version after a 409 on a stale draft and imports with it', async () => {
    const versions: number[] = []
    let reads = 0
    const { user, dialog } = await openDialog({
      [`GET ${base}/library-imports`]: () =>
        Response.json(++reads === 1 ? importsNone : { version: 7, imports: [] }),
      [`POST ${base}/library-imports/preview`]: json(previewClean),
      [`POST ${base}/library-imports`]: async (request) => {
        const { expectedVersion } = (await request.json()) as { expectedVersion: number }
        versions.push(expectedVersion)
        return expectedVersion === 4
          ? Response.json(stale, { status: 409 })
          : Response.json(importsMixed)
      },
    })
    await pickTavern(user)
    await user.click(dialog.getByRole('button', { name: 'Preview' }))
    await dialog.findByRole('region', { name: 'Preview' })
    await user.click(dialog.getByRole('button', { name: 'Import' }))
    expect(await dialog.findByRole('alert')).toHaveTextContent('was changed by another request')

    await user.click(dialog.getByRole('button', { name: 'Reload the latest version' }))
    await waitFor(() => {
      expect(dialog.queryByRole('alert')).not.toBeInTheDocument()
    })
    await user.click(dialog.getByRole('button', { name: 'Preview' }))
    await dialog.findByRole('region', { name: 'Preview' })
    await user.click(dialog.getByRole('button', { name: 'Import' }))

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
    expect(versions).toEqual([4, 7])
  })

  it('does not import twice while the import is running', async () => {
    let imports = 0
    const { user, dialog } = await openDialog({
      [`POST ${base}/library-imports/preview`]: json(previewClean),
      [`POST ${base}/library-imports`]: () => {
        imports += 1
        return new Promise<Response>(() => undefined)
      },
    })
    await pickTavern(user)
    await user.click(dialog.getByRole('button', { name: 'Preview' }))
    await dialog.findByRole('region', { name: 'Preview' })

    await user.click(dialog.getByRole('button', { name: 'Import' }))
    await waitFor(() => {
      expect(dialog.getByRole('button', { name: 'Import' })).toBeDisabled()
    })
    await user.click(dialog.getByRole('button', { name: 'Import' }))

    expect(imports).toBe(1)
  })

  it('keeps the selection frozen while the import runs, so its outcome is shown', async () => {
    let answer: (response: Response) => void = () => undefined
    const { user, dialog } = await openDialog({
      [`POST ${base}/library-imports/preview`]: json(previewClean),
      [`POST ${base}/library-imports`]: () =>
        new Promise<Response>((resolve) => {
          answer = resolve
        }),
    })
    await pickTavern(user)
    await user.click(dialog.getByRole('button', { name: 'Preview' }))
    await dialog.findByRole('region', { name: 'Preview' })

    await user.click(dialog.getByRole('button', { name: 'Import' }))

    await waitFor(() => {
      expect(dialog.getByRole('button', { name: 'Import' })).toBeDisabled()
    })
    expect(dialog.getByRole('checkbox', { name: /Tavern/ })).toBeDisabled()
    expect(dialog.getByRole('checkbox', { name: /Fame/ })).toBeDisabled()
    expect(dialog.getByRole('button', { name: 'Choose another pack' })).toBeDisabled()

    answer(Response.json({ error: 'The draft already has these items.' }, { status: 409 }))

    expect(await dialog.findByRole('alert')).toHaveTextContent('already has these items')
    expect(dialog.getByRole('checkbox', { name: /Tavern/ })).toBeEnabled()
    expect(dialog.getByRole('button', { name: 'Choose another pack' })).toBeEnabled()
  })

  it('offers the reload only for a stale write, not for another 409', async () => {
    const { user, dialog } = await openDialog({
      [`POST ${base}/library-imports/preview`]: json(previewClean),
      [`POST ${base}/library-imports`]: json(
        { error: 'The draft already has these items: scene-types/tavern.' },
        409,
      ),
    })
    await pickTavern(user)
    await user.click(dialog.getByRole('button', { name: 'Preview' }))
    await dialog.findByRole('region', { name: 'Preview' })

    await user.click(dialog.getByRole('button', { name: 'Import' }))

    expect(await dialog.findByRole('alert')).toHaveTextContent('already has these items')
    expect(
      dialog.queryByRole('button', { name: 'Reload the latest version' }),
    ).not.toBeInTheDocument()
  })

  it('keeps the refusal and says so when the reload fails', async () => {
    let reads = 0
    const { user, dialog } = await openDialog({
      [`GET ${base}/library-imports`]: () =>
        ++reads === 1 ? Response.json(importsNone) : new Response('Oops', { status: 500 }),
      [`POST ${base}/library-imports/preview`]: json(previewClean),
      [`POST ${base}/library-imports`]: json(stale, 409),
    })
    await pickTavern(user)
    await user.click(dialog.getByRole('button', { name: 'Preview' }))
    await dialog.findByRole('region', { name: 'Preview' })
    await user.click(dialog.getByRole('button', { name: 'Import' }))
    await dialog.findByText(/was changed by another request/)

    await user.click(dialog.getByRole('button', { name: 'Reload the latest version' }))

    expect(
      await dialog.findByText(/Couldn't reload the latest version/, {}, { timeout: 3000 }),
    ).toBeVisible()
    expect(dialog.getByText(/was changed by another request/)).toBeVisible()
  })

  it('says when the library has no pack', async () => {
    const { dialog } = await openDialog({ 'GET /api/studio/library/packs': json([]) })
    expect(await dialog.findByText('The library has no packs yet.')).toBeVisible()
  })

  it('shows why the packs could not be loaded', async () => {
    const { dialog } = await openDialog({
      'GET /api/studio/library/packs': () => new Response('Oops', { status: 500 }),
    })
    expect(await dialog.findByRole('alert', {}, { timeout: 3000 })).toHaveTextContent(
      'Loading the library packs failed with HTTP 500.',
    )
  })

  it('shows why a pack could not be loaded', async () => {
    const { user, dialog } = await openDialog({
      'GET /api/studio/library/packs/fantasy-basics': json({ error: 'No such pack.' }, 404),
    })

    await user.click(await dialog.findByRole('button', { name: 'Choose Fantasy basics' }))

    expect(await dialog.findByRole('alert')).toHaveTextContent('No such pack.')
  })
})
