import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { oracles, oraclesV1, stormRoll } from '../fixtures'

const base = '/api/studio/game-systems/my-draft'

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

async function openEditor(
  table: string,
  handlers: Record<string, FakeHandler> = {},
  stored: unknown = oracles,
) {
  const user = userEvent.setup()
  const rendered = renderAppAt(`/studio/game-systems/my-draft/oracle-tables/${table}`, {
    'GET /api/auth/me': signedInAs('GAME_MANAGER'),
    [`GET ${base}/oracles`]: json(stored),
    ...handlers,
  })
  await screen.findByRole('form', { name: 'Oracle table' })
  return { user, ...rendered }
}

const entry = (position: number) =>
  within(screen.getByRole('listitem', { name: `Entry ${String(position)}` }))

async function replace(user: ReturnType<typeof userEvent.setup>, label: string, text: string) {
  const field = screen.getByLabelText(label)
  await user.clear(field)
  if (text !== '') {
    await user.type(field, text)
  }
}

const save = () => screen.getByRole('button', { name: 'Save' })

/** The stored `weather` with its name changed, as the server would answer a save. */
type Item = (typeof oracles.tables)[number]
const [weather, ...others] = oracles.tables as [Item, ...Item[]]
const renamed = {
  ...oracles,
  version: 8,
  tables: [{ ...weather, definition: { ...weather.definition, name: 'Skies' } }, ...others],
}
const [rootStep] = stormRoll.steps as [(typeof stormRoll.steps)[number]]

describe('the table editor', () => {
  it('says so, with a way back, when the draft has no such table', async () => {
    renderAppAt('/studio/game-systems/my-draft/oracle-tables/nope', {
      'GET /api/auth/me': signedInAs('GAME_MANAGER'),
      [`GET ${base}/oracles`]: json(oracles),
    })

    expect(await screen.findByRole('alert')).toHaveTextContent('no oracle table “nope”')
    expect(screen.getByRole('link', { name: /Back to my-draft/ })).toHaveAttribute(
      'href',
      '/studio/game-systems/my-draft',
    )
  })

  it('shows why the oracles could not be loaded', async () => {
    renderAppAt('/studio/game-systems/my-draft/oracle-tables/weather', {
      'GET /api/auth/me': signedInAs('GAME_MANAGER'),
      [`GET ${base}/oracles`]: json({ error: 'Nope.' }, 404),
    })

    expect(await screen.findByRole('alert')).toHaveTextContent('does not exist')
  })

  it('shows a ranged table with its entries, and the coverage gap and overlap as they are edited', async () => {
    const { user } = await openEditor('weather')

    expect(screen.getByLabelText('Key')).toHaveValue('weather')
    expect(screen.getByLabelText('Dice')).toHaveValue('1d6')
    expect(screen.getByLabelText('Entry 1 text')).toHaveValue('Clear')
    expect(screen.getByLabelText('Entry 2 nested table')).toHaveValue('storm-kind')
    expect(screen.getByText('Every total is covered exactly once.')).toBeInTheDocument()

    await replace(user, 'Entry 1 max', '3')
    expect(screen.getByRole('list', { name: 'Coverage problems' })).toHaveTextContent(
      'No entry covers 4.',
    )
    await replace(user, 'Entry 1 max', '5')
    expect(screen.getByRole('list', { name: 'Coverage problems' })).toHaveTextContent(
      'More than one entry covers 5.',
    )
    await replace(user, 'Entry 1 max', '4')
    expect(screen.getByText('Every total is covered exactly once.')).toBeInTheDocument()
  })

  it('shows a weighted table with a weight per entry', async () => {
    await openEditor('storm-kind')

    expect(screen.queryByLabelText('Dice')).not.toBeInTheDocument()
    expect(screen.getByLabelText('Entry 1 weight')).toHaveValue('2')
    expect(screen.getByLabelText('Entry 2 weight')).toHaveValue('')
    expect(screen.getByLabelText('Entry 3 weight')).toHaveValue('1')
  })

  it('shows the scene type and effects of an entry, and the entry key of a version 2 table', async () => {
    await openEditor('weather')

    expect(entry(1).getByText('Scene type: calm')).toBeInTheDocument()
    expect(entry(1).getByText('1 effect')).toBeInTheDocument()
    expect(screen.getByLabelText('Entry 1 key')).toHaveValue('clear')
    expect(entry(2).queryByText(/Scene type/)).not.toBeInTheDocument()
  })

  it('hides the entry key of a version 1 table and never sends it', async () => {
    let body: unknown
    const { user } = await openEditor(
      'weather',
      {
        [`PUT ${base}/oracle-tables/weather`]: async (request) => {
          body = await request.json()
          return Response.json(oraclesV1)
        },
      },
      oraclesV1,
    )

    expect(screen.queryByLabelText('Entry 1 key')).not.toBeInTheDocument()
    await replace(user, 'Entry 1 text', 'Fine')
    await user.click(save())

    await waitFor(() => {
      expect(body).toEqual({
        expectedVersion: 2,
        table: {
          key: 'weather',
          name: 'Weather',
          dice: '1d6',
          entries: [{ min: 1, max: 6, text: 'Fine' }],
        },
      })
    })
  })

  it('saves only when something changed and the numbers and texts are valid', async () => {
    const { user } = await openEditor('weather')
    expect(save()).toBeDisabled()

    await replace(user, 'Name', 'Skies')
    expect(save()).toBeEnabled()
    await replace(user, 'Entry 1 min', '1x')
    expect(save()).toBeDisabled()
    await replace(user, 'Entry 1 min', '1')
    await replace(user, 'Entry 1 text', ' ')
    expect(save()).toBeDisabled()
    await replace(user, 'Entry 1 text', 'Clear')
    await replace(user, 'Dice', '')
    expect(save()).toBeDisabled()
    expect(screen.getByText('Enter dice like 1d6 or 2d6.')).toBeInTheDocument()
  })

  it('saves with the version it read, keeps what it does not edit, and uses the answer`s version next', async () => {
    const bodies: unknown[] = []
    const { user } = await openEditor('weather', {
      [`PUT ${base}/oracle-tables/weather`]: async (request) => {
        bodies.push(await request.json())
        return Response.json(bodies.length === 1 ? renamed : { ...renamed, version: 9 })
      },
    })

    await replace(user, 'Name', ' Skies ')
    await user.click(save())
    await waitFor(() => {
      expect(save()).toBeDisabled()
    })
    await replace(user, 'Entry 2 text', 'Gale')
    await user.click(save())

    await waitFor(() => {
      expect(bodies).toHaveLength(2)
    })
    expect(bodies[0]).toEqual({
      expectedVersion: 7,
      table: { ...weather.definition, name: 'Skies' },
    })
    expect(bodies[1]).toMatchObject({ expectedVersion: 8 })
  })

  it('goes to the new key after a rename', async () => {
    const { user, router } = await openEditor('weather', {
      [`PUT ${base}/oracle-tables/weather`]: () =>
        Response.json({
          ...renamed,
          tables: [{ ...weather, definition: { ...weather.definition, key: 'skies' } }, ...others],
        }),
    })

    await replace(user, 'Key', 'skies')
    await user.click(save())

    await waitFor(() => {
      expect(router.state.location.pathname).toBe(
        '/studio/game-systems/my-draft/oracle-tables/skies',
      )
    })
    expect(await screen.findByLabelText('Key')).toHaveValue('skies')
  })

  it('adds and removes entries, a new ranged one empty and a new weighted one at weight 1', async () => {
    const { user } = await openEditor('weather')
    await user.click(screen.getByRole('button', { name: 'Add entry' }))
    expect(screen.getByLabelText('Entry 3 min')).toHaveValue('')
    expect(save()).toBeDisabled()
    await user.click(screen.getByRole('button', { name: 'Remove entry 3' }))
    expect(screen.queryByLabelText('Entry 3 min')).not.toBeInTheDocument()
    expect(save()).toBeDisabled()
  })

  it('adds a weighted entry at weight 1 and keeps the last entry', async () => {
    const { user } = await openEditor('storm-kind')
    await user.click(screen.getByRole('button', { name: 'Add entry' }))
    expect(screen.getByLabelText('Entry 4 weight')).toHaveValue('1')
    await user.click(screen.getByRole('button', { name: 'Remove entry 4' }))
    await user.click(screen.getByRole('button', { name: 'Remove entry 3' }))
    await user.click(screen.getByRole('button', { name: 'Remove entry 2' }))
    expect(screen.getByRole('button', { name: 'Remove entry 1' })).toBeDisabled()
  })

  describe('when the server refuses a save', () => {
    it('attaches a message that points at an entry to its row, and keeps the edits', async () => {
      const { user } = await openEditor('weather', {
        [`PUT ${base}/oracle-tables/weather`]: json(
          { error: 'oracles.tables[0].entries[1]: table "ghost" does not exist.' },
          422,
        ),
      })

      await replace(user, 'Entry 2 text', 'Gale')
      await user.click(save())

      expect(await entry(2).findByRole('alert')).toHaveTextContent('table "ghost" does not exist')
      expect(entry(1).queryByRole('alert')).not.toBeInTheDocument()
      expect(screen.getByLabelText('Entry 2 text')).toHaveValue('Gale')
    })

    it('shows any other message at the form, and clears it on the next edit', async () => {
      const { user } = await openEditor('weather', {
        [`PUT ${base}/oracle-tables/weather`]: json({ error: 'Oracle key "x y" is invalid.' }, 422),
      })

      await replace(user, 'Key', 'x y')
      await user.click(save())
      expect(await screen.findByRole('alert')).toHaveTextContent('Oracle key "x y" is invalid.')
      expect(screen.getByLabelText('Key')).toHaveValue('x y')
      await replace(user, 'Key', 'xy')
      expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    })

    it('shows the entry of another table at the form', async () => {
      const { user } = await openEditor('weather', {
        [`PUT ${base}/oracle-tables/weather`]: json(
          { error: 'oracles.tables[1].entries[0]: bad.' },
          422,
        ),
      })

      await replace(user, 'Name', 'Skies')
      await user.click(save())

      expect(await screen.findByRole('alert')).toHaveTextContent(
        'oracles.tables[1].entries[0]: bad.',
      )
      expect(entry(1).queryByRole('alert')).not.toBeInTheDocument()
    })

    it('lists where the draft uses a table that a rename or a removal would break', async () => {
      const { user } = await openEditor('weather', {
        [`PUT ${base}/oracle-tables/weather`]: json(
          { error: 'Oracle "weather" is used.', paths: ['sceneTypes[1].play[0]', 'flows[0].x'] },
          422,
        ),
      })

      await replace(user, 'Key', 'skies')
      await user.click(save())

      const alert = await screen.findByRole('alert')
      expect(alert).toHaveTextContent('Oracle "weather" is used.')
      expect(alert).toHaveTextContent('Used by sceneTypes[1].play[0], flows[0].x.')
    })

    it('says the draft is gone on a 404 and keeps the edits', async () => {
      const { user } = await openEditor('weather', {
        [`PUT ${base}/oracle-tables/weather`]: json(
          { error: 'GameSystem "my-draft" not found.' },
          404,
        ),
      })

      await replace(user, 'Name', 'Skies')
      await user.click(save())

      expect(await screen.findByRole('alert')).toHaveTextContent('GameSystem "my-draft" not found.')
      expect(screen.getByLabelText('Name')).toHaveValue('Skies')
    })

    it('offers to reload on a 409 and resets the form to the latest stored table', async () => {
      let reads = 0
      const { user } = await openEditor('weather', {
        [`GET ${base}/oracles`]: () => Response.json(++reads === 1 ? oracles : renamed),
        [`PUT ${base}/oracle-tables/weather`]: json(
          { error: 'GameSystem "my-draft" was changed by another request.' },
          409,
        ),
      })

      await replace(user, 'Entry 2 text', 'Gale')
      await user.click(save())
      expect(await screen.findByRole('alert')).toHaveTextContent('unsaved edits')
      expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument()
      await user.click(screen.getByRole('button', { name: 'Reload the latest version' }))

      await waitFor(() => {
        expect(screen.getByLabelText('Name')).toHaveValue('Skies')
      })
      expect(screen.getByLabelText('Entry 2 text')).toHaveValue('Storm')
      expect(await screen.findByRole('button', { name: 'Save' })).toBeDisabled()
      expect(reads).toBe(2)
    })
  })

  describe('the test roll', () => {
    const rolls = `PUT ${base}/oracle-tables/weather`

    it('rolls the saved table without saving, and shows the nested steps in order', async () => {
      let saved = false
      const { user } = await openEditor('weather', {
        [rolls]: () => {
          saved = true
          return Response.json(oracles)
        },
        [`POST ${base}/oracle-tables/weather/test-rolls`]: json(stormRoll),
      })

      await user.click(screen.getByRole('button', { name: 'Test roll' }))

      const steps = within(await screen.findByRole('list', { name: 'Steps' }))
      const [first, second] = steps.getAllByRole('listitem')
      expect(first).toHaveTextContent('Weatherweather1d6= 6Storm')
      expect(second).toHaveTextContent('Nested roll:Storm kindstorm-kind1d4= 2Hail')
      expect(saved).toBe(false)
    })

    it('keeps the last five results, the newest first', async () => {
      let rolled = 0
      const { user } = await openEditor('weather', {
        [`POST ${base}/oracle-tables/weather/test-rolls`]: () =>
          Response.json({
            table: 'weather',
            steps: [{ ...rootStep, total: ++rolled, text: `Roll ${String(rolled)}` }],
          }),
      })

      for (let count = 0; count < 6; count += 1) {
        await user.click(screen.getByRole('button', { name: 'Test roll' }))
        await screen.findByText(`Roll ${String(count + 1)}`)
      }

      const results = screen.getByRole('list', { name: 'Test roll results' })
      expect(within(results).getAllByRole('list', { name: 'Steps' })).toHaveLength(5)
      expect(results.firstElementChild).toHaveTextContent('Roll 6')
      expect(screen.queryByText('Roll 1')).not.toBeInTheDocument()
    })

    it('saves the unsaved edits first, then rolls', async () => {
      const calls: string[] = []
      const { user } = await openEditor('weather', {
        [rolls]: () => {
          calls.push('save')
          return Response.json(renamed)
        },
        [`POST ${base}/oracle-tables/weather/test-rolls`]: () => {
          calls.push('roll')
          return Response.json(stormRoll)
        },
      })

      await replace(user, 'Name', 'Skies')
      expect(screen.getByText(/unsaved edits are saved first/)).toBeInTheDocument()
      await user.click(screen.getByRole('button', { name: 'Test roll' }))

      expect(await screen.findByRole('list', { name: 'Steps' })).toBeInTheDocument()
      expect(calls).toEqual(['save', 'roll'])
    })

    it('does not roll when the save fails', async () => {
      let rolled = false
      const { user } = await openEditor('weather', {
        [rolls]: json({ error: 'Oracle key "x y" is invalid.' }, 422),
        [`POST ${base}/oracle-tables/weather/test-rolls`]: () => {
          rolled = true
          return Response.json(stormRoll)
        },
      })

      await replace(user, 'Key', 'x y')
      await user.click(screen.getByRole('button', { name: 'Test roll' }))

      expect(await screen.findByRole('alert')).toHaveTextContent('Oracle key "x y" is invalid.')
      expect(rolled).toBe(false)
      expect(screen.queryByRole('list', { name: 'Steps' })).not.toBeInTheDocument()
    })

    it('cannot roll while the form is invalid', async () => {
      const { user } = await openEditor('weather')

      await replace(user, 'Entry 1 text', '')

      expect(screen.getByRole('button', { name: 'Test roll' })).toBeDisabled()
    })

    it('shows why a roll was refused', async () => {
      const { user } = await openEditor('weather', {
        [`POST ${base}/oracle-tables/weather/test-rolls`]: json(
          { error: 'Rolled 4 on table "weather", but no entry covers it.' },
          422,
        ),
      })

      await user.click(screen.getByRole('button', { name: 'Test roll' }))

      const panel = within(screen.getByRole('region', { name: 'Test roll' }))
      expect(await panel.findByRole('alert')).toHaveTextContent('Rolled 4 on table "weather"')
    })
  })
})
