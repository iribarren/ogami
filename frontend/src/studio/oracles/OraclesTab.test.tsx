import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { draft, oracles, validDraft } from '../fixtures'

const base = '/api/studio/game-systems/my-draft'

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

async function openOracles(handlers: Record<string, FakeHandler> = {}) {
  const user = userEvent.setup()
  const rendered = renderAppAt('/studio/game-systems/my-draft', {
    'GET /api/auth/me': signedInAs('GAME_MANAGER'),
    'GET /api/studio/game-systems': json([]),
    [`GET ${base}`]: json(draft),
    [`GET ${base}/validation`]: json(validDraft),
    [`GET ${base}/oracles`]: json(oracles),
    ...handlers,
  })
  await user.click(await screen.findByRole('link', { name: 'Oracles' }))
  return { user, ...rendered }
}

const inDialog = () => within(screen.getByRole('dialog'))

const withoutStorm = { ...oracles, version: 8, tables: oracles.tables.slice(0, 1) }

describe('the oracles tab', () => {
  it('lists the tables and the likelihood oracles with their counts and where they are used', async () => {
    await openOracles()

    const tables = within(await screen.findByRole('region', { name: 'Oracle tables' }))
    const [weather, storm] = tables.getAllByRole('listitem')
    expect(weather).toHaveTextContent('Weather')
    expect(weather).toHaveTextContent('weather')
    expect(weather).toHaveTextContent('Ranged 1d6')
    expect(weather).toHaveTextContent('2 entries')
    expect(weather).toHaveTextContent('Used by sceneTypes[1].play[0]')
    expect(storm).toHaveTextContent('Weighted')
    expect(storm).toHaveTextContent('3 entries')
    expect(storm).toHaveTextContent('Not used')
    expect(tables.getByRole('link', { name: 'Weather' })).toHaveAttribute(
      'href',
      '/studio/game-systems/my-draft/oracle-tables/weather',
    )
    const likelihood = within(screen.getByRole('region', { name: 'Likelihood oracles' }))
    expect(likelihood.getByRole('listitem')).toHaveTextContent('Fate question')
    expect(likelihood.getByRole('listitem')).toHaveTextContent('2 levels')
    expect(likelihood.getByRole('listitem')).toHaveTextContent(
      'Used by flows[0].phases[0].steps[1]',
    )
    expect(likelihood.getByRole('link', { name: 'Fate question' })).toHaveAttribute(
      'href',
      '/studio/game-systems/my-draft/likelihood-oracles/fate',
    )
  })

  it('has empty states', async () => {
    await openOracles({ [`GET ${base}/oracles`]: json({ ...oracles, tables: [], likelihood: [] }) })

    expect(await screen.findByText('This draft has no oracle tables yet.')).toBeInTheDocument()
    expect(screen.getByText('This draft has no likelihood oracles.')).toBeInTheDocument()
  })

  it('shows why the oracles could not be loaded', async () => {
    await openOracles({ [`GET ${base}/oracles`]: () => new Response('Oops', { status: 500 }) })

    expect(await screen.findByRole('alert', {}, { timeout: 3000 })).toHaveTextContent(
      'Loading the oracles failed with HTTP 500.',
    )
  })

  it('disables deleting a table the draft uses, and says why', async () => {
    await openOracles()

    const button = await screen.findByRole('button', { name: 'Delete weather' })
    expect(button).toBeDisabled()
    expect(button).toHaveAccessibleDescription('Used by sceneTypes[1].play[0]')
    expect(screen.getByRole('button', { name: 'Delete storm-kind' })).toBeEnabled()
  })

  it('deletes an unused table with the version it read, and shows the answer', async () => {
    let deleted: URL | undefined
    const { user } = await openOracles({
      [`DELETE ${base}/oracle-tables/storm-kind`]: (request) => {
        deleted = new URL(request.url)
        return Response.json(withoutStorm)
      },
    })

    await user.click(await screen.findByRole('button', { name: 'Delete storm-kind' }))
    await user.click(screen.getByRole('button', { name: 'Delete the table' }))

    await waitFor(() => {
      expect(screen.queryByText('storm-kind')).not.toBeInTheDocument()
    })
    expect(deleted?.searchParams.get('expectedVersion')).toBe('7')
  })

  it('shows where a table is used when the server refuses to delete it', async () => {
    const { user } = await openOracles({
      [`DELETE ${base}/oracle-tables/storm-kind`]: json(
        { error: 'Oracle "storm-kind" is used.', paths: ['flows[0].phases[1].steps[2]'] },
        422,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'Delete storm-kind' }))
    await user.click(screen.getByRole('button', { name: 'Delete the table' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Oracle "storm-kind" is used. Used by flows[0].phases[1].steps[2].',
    )
  })

  it('offers to reload when the draft changed since it was read', async () => {
    let reads = 0
    const { user } = await openOracles({
      [`GET ${base}/oracles`]: () => Response.json(++reads === 1 ? oracles : withoutStorm),
      [`DELETE ${base}/oracle-tables/storm-kind`]: json(
        { error: 'GameSystem "my-draft" was changed by another request. Reload it and try again.' },
        409,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'Delete storm-kind' }))
    await user.click(screen.getByRole('button', { name: 'Delete the table' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('was changed by another request')
    await user.click(screen.getByRole('button', { name: 'Reload the latest version' }))

    await waitFor(() => {
      expect(screen.queryByText('storm-kind')).not.toBeInTheDocument()
    })
    expect(reads).toBe(2)
  })

  describe('creating a table', () => {
    async function openDialog(handlers: Record<string, FakeHandler> = {}) {
      const opened = await openOracles(handlers)
      await opened.user.click(await screen.findByRole('button', { name: 'New table' }))
      return opened
    }

    it('posts a ranged table whose one entry covers the dice, and lists the answer', async () => {
      let body: unknown
      const added = {
        ...oracles,
        version: 8,
        tables: [
          ...oracles.tables,
          { definition: { key: 'rumours', name: 'Rumours', dice: '2d6', entries: [] }, usedBy: [] },
        ],
      }
      const { user } = await openDialog({
        [`POST ${base}/oracle-tables`]: async (request) => {
          body = await request.json()
          return Response.json(added, { status: 201 })
        },
      })

      await user.type(inDialog().getByLabelText('Key'), 'rumours')
      await user.type(inDialog().getByLabelText('Name'), 'Rumours')
      await user.clear(inDialog().getByLabelText('Dice'))
      await user.type(inDialog().getByLabelText('Dice'), '2d6')
      await user.click(screen.getByRole('button', { name: 'Create table' }))

      expect(await screen.findByText('Rumours')).toBeInTheDocument()
      expect(body).toEqual({
        expectedVersion: 7,
        table: {
          key: 'rumours',
          name: 'Rumours',
          dice: '2d6',
          entries: [{ min: 2, max: 12, text: 'New entry' }],
        },
      })
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })

    it('posts a weighted table with one entry of weight 1', async () => {
      let body: unknown
      const { user } = await openDialog({
        [`POST ${base}/oracle-tables`]: async (request) => {
          body = await request.json()
          return Response.json(oracles, { status: 201 })
        },
      })

      await user.type(inDialog().getByLabelText('Key'), 'omens')
      await user.type(inDialog().getByLabelText('Name'), 'Omens')
      await user.selectOptions(inDialog().getByLabelText('Kind'), 'weighted')
      expect(inDialog().queryByLabelText('Dice')).not.toBeInTheDocument()
      await user.click(screen.getByRole('button', { name: 'Create table' }))

      await waitFor(() => {
        expect(body).toEqual({
          expectedVersion: 7,
          table: { key: 'omens', name: 'Omens', entries: [{ weight: 1, text: 'New entry' }] },
        })
      })
    })

    it('does not send dice it cannot read', async () => {
      const { user } = await openDialog()

      await user.type(inDialog().getByLabelText('Key'), 'omens')
      await user.type(inDialog().getByLabelText('Name'), 'Omens')
      await user.clear(inDialog().getByLabelText('Dice'))
      await user.type(inDialog().getByLabelText('Dice'), '4d6kh3')

      expect(screen.getByText('Use dice like 1d6, 2d6 or d%.')).toBeInTheDocument()
      expect(screen.getByRole('button', { name: 'Create table' })).toBeDisabled()
    })

    it('sends the key and the name trimmed', async () => {
      let body: { table: { key: string; name: string } } | undefined
      const { user } = await openDialog({
        [`POST ${base}/oracle-tables`]: async (request) => {
          body = (await request.json()) as typeof body
          return Response.json(oracles, { status: 201 })
        },
      })

      await user.type(inDialog().getByLabelText('Key'), ' omens ')
      await user.type(inDialog().getByLabelText('Name'), '  Omens ')
      await user.click(screen.getByRole('button', { name: 'Create table' }))

      await waitFor(() => {
        expect(body?.table).toMatchObject({ key: 'omens', name: 'Omens' })
      })
    })

    it('offers to reload on a 409, and the retry carries the new version', async () => {
      let reads = 0
      const versions: number[] = []
      const { user } = await openDialog({
        [`GET ${base}/oracles`]: () => Response.json(++reads === 1 ? oracles : withoutStorm),
        [`POST ${base}/oracle-tables`]: async (request) => {
          versions.push(((await request.json()) as { expectedVersion: number }).expectedVersion)
          return versions.length === 1
            ? Response.json(
                { error: 'GameSystem "my-draft" was changed by another request.' },
                { status: 409 },
              )
            : Response.json(withoutStorm, { status: 201 })
        },
      })

      await user.type(inDialog().getByLabelText('Key'), 'omens')
      await user.type(inDialog().getByLabelText('Name'), 'Omens')
      await user.click(screen.getByRole('button', { name: 'Create table' }))
      expect(await screen.findByRole('alert')).toHaveTextContent('was changed by another request')
      expect(screen.queryByRole('button', { name: 'Create table' })).not.toBeInTheDocument()
      await user.click(screen.getByRole('button', { name: 'Reload the latest version' }))

      expect(await screen.findByRole('button', { name: 'Create table' })).toBeEnabled()
      expect(reads).toBe(2)
      await user.click(screen.getByRole('button', { name: 'Create table' }))
      await waitFor(() => {
        expect(versions).toEqual([7, 8])
      })
    })

    it('keeps the conflict until the latest version is in, so a retry cannot carry the old one', async () => {
      let reads = 0
      let release: () => void = () => undefined
      const gate = new Promise<void>((resolve) => {
        release = resolve
      })
      const { user } = await openDialog({
        [`GET ${base}/oracles`]: async () => {
          if (++reads === 1) {
            return Response.json(oracles)
          }
          await gate
          return Response.json(withoutStorm)
        },
        [`POST ${base}/oracle-tables`]: json({ error: 'Changed by another request.' }, 409),
      })

      await user.type(inDialog().getByLabelText('Key'), 'omens')
      await user.type(inDialog().getByLabelText('Name'), 'Omens')
      await user.click(screen.getByRole('button', { name: 'Create table' }))
      await user.click(await screen.findByRole('button', { name: 'Reload the latest version' }))

      expect(screen.getByRole('button', { name: 'Reload the latest version' })).toBeDisabled()
      expect(screen.queryByRole('button', { name: 'Create table' })).not.toBeInTheDocument()
      release()
      expect(await screen.findByRole('button', { name: 'Create table' })).toBeEnabled()
    })

    it('says so when the reload fails, and still blocks the retry', async () => {
      let reads = 0
      const { user } = await openDialog({
        [`GET ${base}/oracles`]: () =>
          ++reads === 1
            ? Response.json(oracles)
            : Response.json({ error: 'Gone.' }, { status: 404 }),
        [`POST ${base}/oracle-tables`]: json({ error: 'Changed by another request.' }, 409),
      })

      await user.type(inDialog().getByLabelText('Key'), 'omens')
      await user.type(inDialog().getByLabelText('Name'), 'Omens')
      await user.click(screen.getByRole('button', { name: 'Create table' }))
      await user.click(await screen.findByRole('button', { name: 'Reload the latest version' }))

      expect(await screen.findByText(/Couldn.t reload the latest version/)).toBeInTheDocument()
      expect(screen.queryByRole('button', { name: 'Create table' })).not.toBeInTheDocument()
      expect(screen.getByRole('button', { name: 'Reload the latest version' })).toBeEnabled()
    })

    /** Starts a reload that stays pending, closes the dialog and opens a fresh one. */
    async function reloadThenReopen(answer: Response, newerError: string) {
      let reads = 0
      let release: () => void = () => undefined
      const gate = new Promise<void>((resolve) => {
        release = resolve
      })
      const posts: string[] = ['Changed by another request.', newerError]
      const { user } = await openDialog({
        [`GET ${base}/oracles`]: async () => {
          if (++reads === 1) {
            return Response.json(oracles)
          }
          await gate
          return answer
        },
        [`POST ${base}/oracle-tables`]: () =>
          Response.json({ error: posts.shift() }, { status: 409 }),
      })

      await user.type(inDialog().getByLabelText('Key'), 'omens')
      await user.type(inDialog().getByLabelText('Name'), 'Omens')
      await user.click(screen.getByRole('button', { name: 'Create table' }))
      await user.click(await screen.findByRole('button', { name: 'Reload the latest version' }))
      await user.keyboard('{Escape}')
      await waitFor(() => {
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
      })
      await user.click(screen.getByRole('button', { name: 'New table' }))
      return { user, release }
    }

    it('ignores a reload that fails after the dialog was reopened', async () => {
      const { release } = await reloadThenReopen(
        Response.json({ error: 'Gone.' }, { status: 404 }),
        'Changed again.',
      )

      release()
      expect(await screen.findByText(/Couldn.t refresh the oracles/)).toBeInTheDocument()
      expect(screen.queryByText(/Couldn.t reload the latest version/)).not.toBeInTheDocument()
      expect(inDialog().getByRole('button', { name: 'Create table' })).toBeDisabled()
    })

    it('keeps a newer conflict when an old reload succeeds after the dialog was reopened', async () => {
      const { user, release } = await reloadThenReopen(
        Response.json(withoutStorm),
        'Changed again.',
      )

      await user.type(inDialog().getByLabelText('Key'), 'omens')
      await user.type(inDialog().getByLabelText('Name'), 'Omens')
      await user.click(screen.getByRole('button', { name: 'Create table' }))
      expect(await screen.findByRole('alert')).toHaveTextContent('Changed again.')
      release()

      await waitFor(() => {
        expect(screen.queryByText('storm-kind')).not.toBeInTheDocument()
      })
      expect(screen.getByRole('alert')).toHaveTextContent('Changed again.')
      expect(screen.getByRole('button', { name: 'Reload the latest version' })).toBeEnabled()
    })

    it.each([[422, 'Oracle table key "Bad Key" is invalid.']])(
      'shows the server message of a %i',
      async (status, message) => {
        const { user } = await openDialog({
          [`POST ${base}/oracle-tables`]: json({ error: message }, status),
        })

        await user.type(inDialog().getByLabelText('Key'), 'weather')
        await user.type(inDialog().getByLabelText('Name'), 'Weather')
        await user.click(screen.getByRole('button', { name: 'Create table' }))

        expect(await screen.findByRole('alert')).toHaveTextContent(message)
        expect(screen.getByRole('dialog')).toBeInTheDocument()
      },
    )
  })
  describe('likelihood oracles', () => {
    const fate = oracles.likelihood[0]
    const omens = {
      definition: {
        key: 'omens',
        name: 'Omens',
        sides: 100,
        levels: [{ key: 'even', label: '50/50', target: 50 }],
      },
      usedBy: [],
    }
    const withOmens = { ...oracles, likelihood: [fate, omens] }

    async function openDialog(handlers: Record<string, FakeHandler> = {}) {
      const opened = await openOracles(handlers)
      await opened.user.click(await screen.findByRole('button', { name: 'New likelihood oracle' }))
      return opened
    }

    async function fill(user: ReturnType<typeof userEvent.setup>, key: string, name: string) {
      await user.type(inDialog().getByLabelText('Key'), key)
      await user.type(inDialog().getByLabelText('Name'), name)
    }

    it('posts a likelihood oracle with two levels on a d100, key and name trimmed, and lists it', async () => {
      let body: unknown
      const { user } = await openDialog({
        [`POST ${base}/likelihood-oracles`]: async (request) => {
          body = await request.json()
          return Response.json({ ...withOmens, version: 8 }, { status: 201 })
        },
      })

      await fill(user, ' omens ', ' Omens ')
      await user.click(screen.getByRole('button', { name: 'Create likelihood oracle' }))

      expect(await screen.findByText('Omens')).toBeInTheDocument()
      expect(body).toEqual({
        expectedVersion: 7,
        oracle: {
          key: 'omens',
          name: 'Omens',
          sides: 100,
          levels: [
            { key: 'unlikely', label: 'Unlikely', target: 35 },
            { key: 'likely', label: 'Likely', target: 65 },
          ],
        },
      })
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })

    it('needs a key and a name', async () => {
      const { user } = await openDialog()

      const create = screen.getByRole('button', { name: 'Create likelihood oracle' })
      expect(create).toBeDisabled()
      await fill(user, 'omens', ' ')
      expect(create).toBeDisabled()
    })

    it('shows the server message of a 422', async () => {
      const { user } = await openDialog({
        [`POST ${base}/likelihood-oracles`]: json(
          { error: 'Oracle key "Bad Key" is invalid.' },
          422,
        ),
      })

      await fill(user, 'fate', 'Fate')
      await user.click(screen.getByRole('button', { name: 'Create likelihood oracle' }))

      expect(await screen.findByRole('alert')).toHaveTextContent('Oracle key "Bad Key" is invalid.')
      expect(screen.getByRole('dialog')).toBeInTheDocument()
    })

    it('offers to reload on a 409, and the retry carries the new version', async () => {
      let reads = 0
      const versions: number[] = []
      const { user } = await openDialog({
        [`GET ${base}/oracles`]: () =>
          Response.json(++reads === 1 ? oracles : { ...oracles, version: 8 }),
        [`POST ${base}/likelihood-oracles`]: async (request) => {
          versions.push(((await request.json()) as { expectedVersion: number }).expectedVersion)
          return versions.length === 1
            ? Response.json({ error: 'Changed by another request.' }, { status: 409 })
            : Response.json({ ...withOmens, version: 9 }, { status: 201 })
        },
      })

      await fill(user, 'omens', 'Omens')
      await user.click(screen.getByRole('button', { name: 'Create likelihood oracle' }))
      expect(await screen.findByRole('alert')).toHaveTextContent('Changed by another request.')
      await user.click(screen.getByRole('button', { name: 'Reload the latest version' }))

      await user.click(await screen.findByRole('button', { name: 'Create likelihood oracle' }))
      await waitFor(() => {
        expect(versions).toEqual([7, 8])
      })
    })

    it('disables deleting an oracle the draft uses, and says why', async () => {
      await openOracles({ [`GET ${base}/oracles`]: json(withOmens) })

      const button = await screen.findByRole('button', { name: 'Delete fate' })
      expect(button).toBeDisabled()
      expect(button).toHaveAccessibleDescription('Used by flows[0].phases[0].steps[1]')
      expect(screen.getByRole('button', { name: 'Delete omens' })).toBeEnabled()
    })

    it('deletes an unused oracle with the version it read, and shows the answer', async () => {
      let deleted: URL | undefined
      const { user } = await openOracles({
        [`GET ${base}/oracles`]: json(withOmens),
        [`DELETE ${base}/likelihood-oracles/omens`]: (request) => {
          deleted = new URL(request.url)
          return Response.json({ ...oracles, version: 8 })
        },
      })

      await user.click(await screen.findByRole('button', { name: 'Delete omens' }))
      await user.click(screen.getByRole('button', { name: 'Delete the likelihood oracle' }))

      await waitFor(() => {
        expect(screen.queryByText('omens')).not.toBeInTheDocument()
      })
      expect(deleted?.searchParams.get('expectedVersion')).toBe('7')
    })

    it('shows where an oracle is used when the server refuses to delete it', async () => {
      const { user } = await openOracles({
        [`GET ${base}/oracles`]: json(withOmens),
        [`DELETE ${base}/likelihood-oracles/omens`]: json(
          { error: 'Oracle "omens" is used.', paths: ['flows[0].phases[1].steps[2]'] },
          422,
        ),
      })

      await user.click(await screen.findByRole('button', { name: 'Delete omens' }))
      await user.click(screen.getByRole('button', { name: 'Delete the likelihood oracle' }))

      expect(await screen.findByRole('alert')).toHaveTextContent(
        'Oracle "omens" is used. Used by flows[0].phases[1].steps[2].',
      )
    })

    it('offers to reload when the draft changed since it was read', async () => {
      let reads = 0
      const { user } = await openOracles({
        [`GET ${base}/oracles`]: () => Response.json(++reads === 1 ? withOmens : oracles),
        [`DELETE ${base}/likelihood-oracles/omens`]: json(
          { error: 'Changed by another request.' },
          409,
        ),
      })

      await user.click(await screen.findByRole('button', { name: 'Delete omens' }))
      await user.click(screen.getByRole('button', { name: 'Delete the likelihood oracle' }))
      expect(await screen.findByRole('alert')).toHaveTextContent('Changed by another request.')
      await user.click(screen.getByRole('button', { name: 'Reload the latest version' }))

      await waitFor(() => {
        expect(screen.queryByText('omens')).not.toBeInTheDocument()
      })
      expect(reads).toBe(2)
    })
  })
})
