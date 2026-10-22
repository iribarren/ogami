import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { chooseOption, listOptions } from '@/test/chooseOption'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { oracles, oraclesV1, stormRoll, narrativeScenes } from '../fixtures'

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
    [`GET ${base}/narrative`]: json(narrativeScenes),
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

/** Opens the details of an entry (its disclosure starts closed). */
async function openDetails(user: ReturnType<typeof userEvent.setup>, position: number) {
  const toggle = screen.getByRole('button', { name: `Entry ${String(position)} details` })
  if (toggle.getAttribute('aria-expanded') !== 'true') {
    await user.click(toggle)
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
      [`GET ${base}/narrative`]: json(narrativeScenes),
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
    expect(screen.getByText('Covers 1–6')).toBeInTheDocument()

    await replace(user, 'Entry 1 max', '3')
    expect(screen.getByRole('list', { name: 'Coverage problems' })).toHaveTextContent(
      'No entry covers 4.',
    )
    await replace(user, 'Entry 1 max', '5')
    expect(screen.getByRole('list', { name: 'Coverage problems' })).toHaveTextContent(
      'More than one entry covers 5.',
    )
    await replace(user, 'Entry 1 max', '4')
    expect(screen.getByText('Covers 1–6')).toBeInTheDocument()
  })

  it('shows a weighted table with a weight per entry', async () => {
    await openEditor('storm-kind')

    expect(screen.queryByLabelText('Dice')).not.toBeInTheDocument()
    expect(screen.getByLabelText('Entry 1 weight')).toHaveValue('2')
    expect(screen.getByLabelText('Entry 2 weight')).toHaveValue('')
    expect(screen.getByLabelText('Entry 3 weight')).toHaveValue('1')
  })

  it('shows the Scene Type and effects of an entry, and the entry key of a version 2 table', async () => {
    const { user } = await openEditor('weather')

    // The stored Scene Type is not one of the draft's: it stays selected, flagged.
    const sceneType = screen.getByLabelText('Entry 1 Scene Type')
    expect(sceneType).toHaveValue('calm')
    expect(sceneType).toBeInvalid()
    expect(entry(1).getByText(/no Scene Type/)).toBeInTheDocument()
    expect(screen.getByLabelText('Entry 2 Scene Type')).toHaveValue('')
    expect(
      (await listOptions(user, screen.getByLabelText('Entry 2 Scene Type'))).map(
        (option) => option.label,
      ),
    ).toEqual(['None', 'Ambush', 'Quiet', 'Odd'])
    // The stored effect has a kind the editor does not know: kept, read-only.
    expect(entry(1).getByText(/kind or shape this editor cannot edit/)).toBeInTheDocument()
    expect(screen.getByLabelText('Entry 1 key')).toHaveValue('clear')
  })

  it('shows neither Scene Type nor effects for a version 1 table', async () => {
    await openEditor('weather', {}, oraclesV1)

    expect(screen.queryByLabelText('Entry 1 Scene Type')).not.toBeInTheDocument()
    expect(screen.queryByText('Entry 1 effects')).not.toBeInTheDocument()
  })

  it('sends back exactly what was stored for the Scene Type and effects of an entry it did not edit', async () => {
    const bodies: unknown[] = []
    const { user } = await openEditor('weather', {
      [`PUT ${base}/oracle-tables/weather`]: async (request) => {
        bodies.push(await request.json())
        return Response.json(renamed)
      },
    })

    await replace(user, 'Entry 2 text', 'Gale')
    await user.click(save())

    await waitFor(() => {
      expect(bodies).toHaveLength(1)
    })
    const [first, second] = weather.definition.entries
    expect(bodies[0]).toEqual({
      expectedVersion: 7,
      table: { ...weather.definition, entries: [first, { ...second, text: 'Gale' }] },
    })
  })

  it('picks a Scene Type and adds an effect to an entry, and removes the Scene Type again', async () => {
    const bodies: unknown[] = []
    const { user } = await openEditor('weather', {
      [`PUT ${base}/oracle-tables/weather`]: async (request) => {
        bodies.push(await request.json())
        return Response.json(renamed)
      },
    })

    await openDetails(user, 1)
    await openDetails(user, 2)
    await chooseOption(user, 'Entry 2 Scene Type', 'quiet')
    await chooseOption(user, 'New effect kind', 'endPhase', entry(2))
    await user.click(entry(2).getByRole('button', { name: 'Add effect' }))
    await chooseOption(user, 'Entry 1 Scene Type', '')
    await user.click(save())

    await waitFor(() => {
      expect(bodies).toHaveLength(1)
    })
    const [first, second] = weather.definition.entries as [{ sceneType?: string }, object]
    const withoutScene = { ...first }
    delete withoutScene.sceneType
    expect(bodies[0]).toEqual({
      expectedVersion: 7,
      table: {
        ...weather.definition,
        entries: [withoutScene, { ...second, sceneType: 'quiet', effects: [{ kind: 'endPhase' }] }],
      },
    })
  })

  it('offers no sheet effect for an entry, and holds at ten effects', async () => {
    const { user } = await openEditor('storm-kind')

    await openDetails(user, 1)
    const kinds = (await listOptions(user, entry(1).getByLabelText('New effect kind'))).map(
      (option) => option.label,
    )
    expect(kinds).not.toContain('sheet')
    for (let count = 0; count < 10; count += 1) {
      await user.click(entry(1).getByRole('button', { name: 'Add effect' }))
    }
    expect(entry(1).getByRole('button', { name: 'Add effect' })).toBeDisabled()
  })

  it('cannot be saved while an effect value is not a whole number', async () => {
    const { user } = await openEditor('storm-kind')

    await openDetails(user, 1)
    await user.click(entry(1).getByRole('button', { name: 'Add effect' }))
    await chooseOption(user, 'Tracker', 'heat', entry(1))
    await user.clear(entry(1).getByLabelText('Value'))
    await user.type(entry(1).getByLabelText('Value'), 'x')

    expect(save()).toBeDisabled()
    expect(entry(1).getByLabelText('Value')).toBeInvalid()
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

  const afterRename = {
    ...renamed,
    tables: [{ ...weather, definition: { ...weather.definition, key: 'skies' } }, ...others],
  }

  /** Holds the navigation back, as a slow router would: the cache answers before the URL does. */
  function slowNavigation(router: { navigate: (options: never) => Promise<void> }) {
    const navigate = router.navigate.bind(router)
    vi.spyOn(router, 'navigate').mockImplementation(async (options) => {
      await new Promise((resolve) => setTimeout(resolve, 100))
      await navigate(options)
    })
  }

  /** Records whether the not-found state is ever on screen, even for one render. */
  function watchForNotFound() {
    const seen = { notFound: false }
    const observer = new MutationObserver(() => {
      seen.notFound ||= document.body.textContent.includes('no oracle table')
    })
    observer.observe(document.body, { childList: true, subtree: true, characterData: true })
    return {
      seen,
      stop: () => {
        observer.disconnect()
      },
    }
  }

  it('goes to the new key after a rename, without ever showing the table as missing', async () => {
    const { user, router } = await openEditor('weather', {
      [`PUT ${base}/oracle-tables/weather`]: () => Response.json(afterRename),
    })
    slowNavigation(router)
    const watch = watchForNotFound()

    await replace(user, 'Key', 'skies')
    await user.click(save())

    await waitFor(() => {
      expect(router.state.location.pathname).toBe(
        '/studio/game-systems/my-draft/oracle-tables/skies',
      )
    })
    expect(await screen.findByLabelText('Key')).toHaveValue('skies')
    watch.stop()
    expect(watch.seen.notFound).toBe(false)
  })

  it('lets go of the renamed key when the navigation is refused, and leaves the URL to decide', async () => {
    const { user, router } = await openEditor('weather', {
      [`PUT ${base}/oracle-tables/weather`]: () => Response.json(afterRename),
    })
    vi.spyOn(router, 'navigate').mockRejectedValue(new Error('Navigation refused.'))

    await replace(user, 'Key', 'skies')
    await user.click(save())

    expect(await screen.findByText(/no oracle table/)).toBeInTheDocument()
    expect(router.state.location.pathname).toBe(
      '/studio/game-systems/my-draft/oracle-tables/weather',
    )
  })

  it('renames through a test roll: saves, goes to the new key, rolls it and keeps the result', async () => {
    const rolled: string[] = []
    const { user, router } = await openEditor('weather', {
      [`PUT ${base}/oracle-tables/weather`]: () => Response.json(afterRename),
      [`POST ${base}/oracle-tables/skies/test-rolls`]: () => {
        rolled.push('skies')
        return Response.json(stormRoll)
      },
    })
    slowNavigation(router)
    const watch = watchForNotFound()

    await replace(user, 'Key', 'skies')
    await user.click(screen.getByRole('button', { name: 'Test roll' }))

    expect(await screen.findByRole('list', { name: 'Steps' })).toBeInTheDocument()
    expect(rolled).toEqual(['skies'])
    expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft/oracle-tables/skies')
    expect(screen.getByLabelText('Key')).toHaveValue('skies')
    watch.stop()
    expect(watch.seen.notFound).toBe(false)
  })

  it('adds and removes entries, a new ranged one after the last range', async () => {
    const { user } = await openEditor('weather')
    await user.click(screen.getByRole('button', { name: 'Add entry' }))
    expect(screen.getByLabelText('Entry 3 min')).toHaveValue('7')
    expect(screen.getByLabelText('Entry 3 max')).toHaveValue('7')
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

  it('opens and closes the details of an entry, which a summary badge counts', async () => {
    const { user } = await openEditor('weather')
    const toggle = screen.getByRole('button', { name: 'Entry 2 details' })

    expect(toggle).toHaveAttribute('aria-expanded', 'false')
    expect(screen.getByLabelText('Entry 2 nested table')).not.toBeVisible()
    expect(entry(2).getByText(/^Table storm-kind/)).toBeInTheDocument()
    await user.click(toggle)
    expect(toggle).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByLabelText('Entry 2 nested table')).toBeVisible()
    await user.click(toggle)
    expect(screen.getByLabelText('Entry 2 nested table')).not.toBeVisible()
  })

  it('opens the details an error points at', async () => {
    const { user } = await openEditor('weather', {
      [`PUT ${base}/oracle-tables/weather`]: json(
        { error: 'oracles.tables[0].entries[1].sceneType: unknown Scene Type "ghost"' },
        422,
      ),
    })

    await replace(user, 'Entry 2 text', 'Gale')
    expect(screen.getByLabelText('Entry 2 Scene Type')).not.toBeVisible()
    await user.click(save())

    expect(await entry(2).findByRole('alert')).toHaveTextContent('unknown Scene Type "ghost"')
    expect(screen.getByRole('button', { name: 'Entry 2 details' })).toHaveAttribute(
      'aria-expanded',
      'true',
    )
  })

  describe('pasting entries', () => {
    async function paste(user: ReturnType<typeof userEvent.setup>, text: string) {
      await user.click(screen.getByRole('button', { name: 'Paste entries' }))
      const dialog = within(await screen.findByRole('dialog', { name: 'Paste entries' }))
      await user.click(dialog.getByLabelText('Entries to paste'))
      await user.paste(text)
      return dialog
    }

    it('adds the lines after the last range by default, and changes only the draft', async () => {
      const { user } = await openEditor('weather')

      const dialog = await paste(user, '7 Fog\n\nHail')
      await user.click(dialog.getByRole('button', { name: 'Use 2 entries' }))

      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
      expect(screen.getByLabelText('Entry 3 text')).toHaveValue('Fog')
      expect(screen.getByLabelText('Entry 3 min')).toHaveValue('7')
      expect(screen.getByLabelText('Entry 4 text')).toHaveValue('Hail')
      expect(screen.getByLabelText('Entry 4 max')).toHaveValue('8')
      expect(screen.getByLabelText('Entry 2 text')).toHaveValue(weather.definition.entries[1]?.text)
      expect(save()).toBeEnabled()
    })

    it('replaces every entry on request', async () => {
      const { user } = await openEditor('weather')

      await user.click(screen.getByRole('button', { name: 'Paste entries' }))
      const dialog = within(await screen.findByRole('dialog', { name: 'Paste entries' }))
      await user.click(dialog.getByRole('radio', { name: 'Replace all' }))
      await user.click(dialog.getByLabelText('Entries to paste'))
      await user.paste('1-3. Calm\n4–6: Gale')
      await user.click(dialog.getByRole('button', { name: 'Use 2 entries' }))

      expect(screen.queryByLabelText('Entry 3 text')).not.toBeInTheDocument()
      expect(screen.getByLabelText('Entry 1 max')).toHaveValue('3')
      expect(screen.getByLabelText('Entry 2 min')).toHaveValue('4')
      expect(screen.getByLabelText('Entry 2 text')).toHaveValue('Gale')
    })

    it('names a bad line and applies nothing', async () => {
      const { user } = await openEditor('weather')

      const dialog = await paste(user, 'Fog\n5-3 Hail')

      expect(dialog.getByRole('alert')).toHaveTextContent(
        'Line 2: the range 5–3 has its min above its max.',
      )
      expect(dialog.getByRole('button', { name: 'Use 0 entries' })).toBeDisabled()
      await user.click(dialog.getByRole('button', { name: 'Cancel' }))
      expect(screen.queryByLabelText('Entry 3 text')).not.toBeInTheDocument()
    })

    it('gives a weighted table weight 1 and keeps leading numbers in the text', async () => {
      const { user } = await openEditor('storm-kind')

      const dialog = await paste(user, '2 Hail')
      await user.click(dialog.getByRole('button', { name: 'Use 1 entry' }))

      expect(screen.getByLabelText('Entry 4 text')).toHaveValue('2 Hail')
      expect(screen.getByLabelText('Entry 4 weight')).toHaveValue('1')
    })
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

    it('attaches a message about the Scene Type of an entry to its field', async () => {
      const { user } = await openEditor('weather', {
        [`PUT ${base}/oracle-tables/weather`]: json(
          { error: 'oracles.tables[0].entries[1].sceneType: unknown Scene Type "ghost"' },
          422,
        ),
      })

      await openDetails(user, 2)
      await chooseOption(user, 'Entry 2 Scene Type', 'quiet')
      await user.click(save())

      const field = screen.getByLabelText('Entry 2 Scene Type')
      expect(await entry(2).findByRole('alert')).toHaveTextContent('unknown Scene Type "ghost"')
      expect(field).toBeInvalid()
      expect(field).toHaveValue('quiet')
    })

    it('attaches a message about an effect of an entry to that effect', async () => {
      const { user } = await openEditor('weather', {
        [`PUT ${base}/oracle-tables/weather`]: json(
          {
            error: 'oracles.tables[0].entries[1].effects[0].sceneType: unknown Scene Type "ghost"',
          },
          422,
        ),
      })

      await openDetails(user, 2)
      await chooseOption(user, 'New effect kind', 'nextScene', entry(2))
      await user.click(entry(2).getByRole('button', { name: 'Add effect' }))
      await user.click(save())

      const card = within(await entry(2).findByRole('group', { name: 'Effect 1' }))
      expect(await card.findByText('unknown Scene Type "ghost"')).toBeInTheDocument()
      expect(entry(2).queryByLabelText('Entry 2 Scene Type')).toBeValid()
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

  describe('a failed reload', () => {
    it('says so and keeps the form as it is', async () => {
      let reads = 0
      const { user } = await openEditor('weather', {
        [`GET ${base}/oracles`]: () =>
          reads++ === 0
            ? Response.json(oracles)
            : Response.json({ error: 'Gone.' }, { status: 404 }),
        [`PUT ${base}/oracle-tables/weather`]: json({ error: 'Changed by another request.' }, 409),
      })

      await replace(user, 'Entry 2 text', 'Gale')
      await user.click(save())
      await user.click(await screen.findByRole('button', { name: 'Reload the latest version' }))

      expect(await screen.findByText(/Couldn.t reload the latest version/)).toBeInTheDocument()
      expect(screen.getByLabelText('Entry 2 text')).toHaveValue('Gale')
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

describe('the oracle table editor page', () => {
  const route = '/studio/game-systems/my-draft/oracle-tables/weather'
  const handlers = (narrative: FakeHandler) => ({
    'GET /api/auth/me': signedInAs('GAME_MANAGER'),
    [`GET ${base}/oracles`]: json(oracles),
    [`GET ${base}/narrative`]: narrative,
  })

  it('shows the loading state, not the form, while the narrative loads', async () => {
    renderAppAt(
      route,
      handlers(() => new Promise<Response>(() => undefined)),
    )

    expect(await screen.findByText('Loading the table…')).toBeInTheDocument()
    expect(screen.queryByRole('form', { name: 'Oracle table' })).not.toBeInTheDocument()
  })

  it('shows the error, not the form, when the narrative fails to load', async () => {
    renderAppAt(route, handlers(json({ error: 'Narrative down' }, 404)))

    expect(await screen.findByRole('alert')).toHaveTextContent('Narrative down')
    expect(screen.queryByRole('form', { name: 'Oracle table' })).not.toBeInTheDocument()
  })
})
