import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { oracles, oraclesV1Likelihood, oraclesWithChaos, narrativeScenes } from '../fixtures'

const base = '/api/studio/game-systems/my-draft'
const put = `PUT ${base}/likelihood-oracles/fate`

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

async function openEditor(
  oracle: string,
  handlers: Record<string, FakeHandler> = {},
  stored: unknown = oracles,
) {
  const user = userEvent.setup()
  const rendered = renderAppAt(`/studio/game-systems/my-draft/likelihood-oracles/${oracle}`, {
    'GET /api/auth/me': signedInAs('GAME_MANAGER'),
    [`GET ${base}/oracles`]: json(stored),
    [`GET ${base}/narrative`]: json(narrativeScenes),
    ...handlers,
  })
  await screen.findByRole('form', { name: 'Likelihood oracle' })
  return { user, ...rendered }
}

async function replace(user: ReturnType<typeof userEvent.setup>, label: string, text: string) {
  const field = screen.getByLabelText(label)
  await user.clear(field)
  if (text !== '') {
    await user.type(field, text)
  }
}

const save = () => screen.getByRole('button', { name: 'Save' })

/** What the server answers to a save: the same oracles at the next version. */
const saved = (likelihood: unknown) => ({ ...oracles, version: 8, likelihood })
const [fate] = oracles.likelihood as [(typeof oracles.likelihood)[number]]
const [chaosFate] = oraclesWithChaos.likelihood as [(typeof oraclesWithChaos.likelihood)[number]]
/** The stored oracle with a chaos factor whose tracker is `tracker`, or has none. */
const withTracker = (tracker: string | undefined) => ({
  ...oraclesWithChaos,
  likelihood: [
    {
      ...chaosFate,
      definition: {
        ...chaosFate.definition,
        chaos: { min: 1, max: 9, neutral: 5, shiftPerPoint: 5, ...(tracker && { tracker }) },
      },
    },
  ],
})
const conflict = json({ error: 'GameSystem "my-draft" was changed by another request.' }, 409)

/** Records the body of every save. */
function recording(bodies: unknown[], answer: unknown): FakeHandler {
  return async (request) => {
    const body = (await request.json()) as { oracle: unknown }
    bodies.push(body)
    // The server stores what it was sent.
    return Response.json({
      ...(answer as object),
      likelihood: [{ ...fate, definition: body.oracle }],
    })
  }
}

describe('the likelihood oracle editor', () => {
  it('says so, with a way back, when the draft has no such oracle', async () => {
    renderAppAt('/studio/game-systems/my-draft/likelihood-oracles/nope', {
      'GET /api/auth/me': signedInAs('GAME_MANAGER'),
      [`GET ${base}/oracles`]: json(oracles),
      [`GET ${base}/narrative`]: json(narrativeScenes),
    })

    expect(await screen.findByRole('alert')).toHaveTextContent('no likelihood oracle “nope”')
    expect(screen.getByRole('link', { name: /Back to my-draft/ })).toHaveAttribute(
      'href',
      '/studio/game-systems/my-draft',
    )
  })

  it('shows why the oracles could not be loaded', async () => {
    renderAppAt('/studio/game-systems/my-draft/likelihood-oracles/fate', {
      'GET /api/auth/me': signedInAs('GAME_MANAGER'),
      [`GET ${base}/oracles`]: json({ error: 'Nope.' }, 404),
    })

    expect(await screen.findByRole('alert')).toHaveTextContent('does not exist')
  })

  it('shows an oracle without chaos: its levels, no chaos fields, no random event table chosen', async () => {
    await openEditor('fate')

    expect(screen.getByLabelText('Key')).toHaveValue('fate')
    expect(screen.getByLabelText('Name')).toHaveValue('Fate question')
    expect(screen.getByLabelText('Sides')).toHaveValue('100')
    expect(screen.getByLabelText('Level 2 key')).toHaveValue('likely')
    expect(screen.getByLabelText('Level 2 label')).toHaveValue('Likely')
    expect(screen.getByLabelText('Level 2 target')).toHaveValue('65')
    expect(screen.getByLabelText('Has a chaos factor')).not.toBeChecked()
    expect(screen.queryByLabelText('Chaos min')).not.toBeInTheDocument()
    expect(screen.getByLabelText('Random event table')).toHaveValue('')
    expect(save()).toBeDisabled()
  })

  it('shows chaos, exceptional percent, random event table and the chaos tracker, flagged when the draft has none like it', async () => {
    await openEditor('fate', {}, oraclesWithChaos)

    expect(screen.getByLabelText('Has a chaos factor')).toBeChecked()
    expect(screen.getByLabelText('Chaos min')).toHaveValue('1')
    expect(screen.getByLabelText('Chaos max')).toHaveValue('9')
    expect(screen.getByLabelText('Chaos neutral')).toHaveValue('5')
    expect(screen.getByLabelText('Chaos shift per point')).toHaveValue('5')
    expect(screen.getByLabelText('Exceptional percent')).toHaveValue('20')
    expect(screen.getByLabelText('Random event table')).toHaveValue('storm-kind')
    // The stored tracker is not one of the draft's: it stays selected, flagged.
    const tracker = screen.getByLabelText('Chaos tracker')
    expect(tracker).toHaveValue('chaos')
    expect(tracker).toBeInvalid()
    expect(screen.getByText('The draft has no tracker "chaos".')).toBeInTheDocument()
  })

  it('offers the counters of the draft as chaos trackers, and flags a stored one that is not a counter', async () => {
    const stored = withTracker('alarm')
    await openEditor('fate', {}, stored)

    expect(
      within(screen.getByLabelText('Chaos tracker'))
        .getAllByRole('option')
        .map((option) => option.textContent),
    ).toEqual(['None', 'alarm (not a counter)', 'Heat'])
    expect(screen.getByLabelText('Chaos tracker')).toHaveValue('alarm')
    expect(screen.getByText('The tracker "alarm" is not a counter.')).toBeInTheDocument()
  })

  it('picks a chaos tracker, and sends none when None is picked', async () => {
    const bodies: unknown[] = []
    const stored = withTracker(undefined)
    const { user } = await openEditor(
      'fate',
      { [put]: recording(bodies, { ...stored, version: 8 }) },
      stored,
    )

    expect(screen.getByLabelText('Chaos tracker')).toHaveValue('')
    await user.selectOptions(screen.getByLabelText('Chaos tracker'), 'heat')
    await user.click(save())
    await waitFor(() => {
      expect(bodies).toHaveLength(1)
    })
    expect(bodies[0]).toMatchObject({
      oracle: { chaos: { min: 1, max: 9, neutral: 5, shiftPerPoint: 5, tracker: 'heat' } },
    })

    await user.selectOptions(await screen.findByLabelText('Chaos tracker'), '')
    await user.click(save())
    await waitFor(() => {
      expect(bodies).toHaveLength(2)
    })
    expect((bodies[1] as { oracle: { chaos: object } }).oracle.chaos).not.toHaveProperty('tracker')
  })

  it('has no chaos tracker field without a chaos factor', async () => {
    await openEditor('fate')

    expect(screen.queryByLabelText('Chaos tracker')).not.toBeInTheDocument()
  })

  it('hides the version 2 fields of a version 1 draft and never sends them', async () => {
    const bodies: unknown[] = []
    const stored = oraclesV1Likelihood
    const { user } = await openEditor(
      'fate',
      { [put]: recording(bodies, { ...stored, version: 3 }) },
      stored,
    )

    expect(screen.queryByLabelText('Random event table')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Chaos tracker')).not.toBeInTheDocument()
    await replace(user, 'Name', 'Luck')
    await user.click(save())

    await waitFor(() => {
      expect(bodies).toHaveLength(1)
    })
    expect(bodies[0]).toEqual({
      expectedVersion: 2,
      oracle: { ...stored.likelihood[0]?.definition, name: 'Luck' },
    })
  })

  it('saves with the version it read and round-trips what it does not edit', async () => {
    const bodies: unknown[] = []
    const withExtras = {
      ...chaosFate.definition,
      note: 'kept',
      levels: fate.definition.levels.map((level) => ({ ...level, hint: 'kept too' })),
      chaos: { ...chaosFate.definition.chaos, extra: 1 },
    }
    const stored = { ...oraclesWithChaos, likelihood: [{ ...chaosFate, definition: withExtras }] }
    const { user } = await openEditor(
      'fate',
      { [put]: recording(bodies, { ...stored, version: 8 }) },
      stored,
    )

    await replace(user, 'Name', 'Luck')
    await replace(user, 'Level 1 target', '55')
    await replace(user, 'Chaos max', '8')
    await replace(user, 'Exceptional percent', '10')
    await user.selectOptions(screen.getByLabelText('Random event table'), 'weather')
    await user.click(save())

    await waitFor(() => {
      expect(bodies).toHaveLength(1)
    })
    expect(bodies[0]).toEqual({
      expectedVersion: 7,
      oracle: {
        ...withExtras,
        name: 'Luck',
        levels: [{ ...withExtras.levels[0], target: 55 }, { ...withExtras.levels[1] }],
        chaos: { ...withExtras.chaos, max: 8 },
        exceptionalPercent: 10,
        randomEvent: { table: 'weather' },
      },
    })
    expect(await screen.findByRole('button', { name: 'Save' })).toBeDisabled()
  })

  it('switches chaos on with empty fields that must be filled, and off again', async () => {
    const bodies: unknown[] = []
    const { user } = await openEditor('fate', { [put]: recording(bodies, saved([])) })

    await user.click(screen.getByLabelText('Has a chaos factor'))
    expect(save()).toBeDisabled()
    await replace(user, 'Chaos min', '1')
    await replace(user, 'Chaos max', '9')
    await replace(user, 'Chaos neutral', '5')
    await replace(user, 'Chaos shift per point', '10')
    await user.click(save())

    await waitFor(() => {
      expect(bodies).toHaveLength(1)
    })
    expect(bodies[0]).toMatchObject({
      oracle: { chaos: { min: 1, max: 9, neutral: 5, shiftPerPoint: 10 } },
    })
    await user.click(screen.getByLabelText('Has a chaos factor'))
    expect(screen.queryByLabelText('Chaos min')).not.toBeInTheDocument()
  })

  it.each([
    ['sides that are not a whole number', 'Sides', '1.5', 'Sides must be a whole number.'],
    ['an empty name', 'Name', '', 'Key and name are required.'],
    ['an empty key', 'Key', ' ', 'Key and name are required.'],
    ['an unreadable target', 'Level 1 target', '5x', 'Every level target must be a whole number.'],
    ['an empty level key', 'Level 1 key', '', 'Every level needs a key and a label.'],
    ['an empty level label', 'Level 1 label', '', 'Every level needs a key and a label.'],
    ['a duplicate level key', 'Level 1 key', 'likely', 'Level keys must be unique.'],
    ['an unreadable exceptional percent', 'Exceptional percent', 'a', 'whole number'],
  ])('cannot be saved with %s', async (_what, label, text, problem) => {
    const { user } = await openEditor('fate')

    await replace(user, label, text)

    expect(save()).toBeDisabled()
    expect(screen.getByRole('list', { name: 'Problems' })).toHaveTextContent(problem)
  })

  it('adds levels empty at the end and removes them, keeping the order and the last one', async () => {
    const bodies: unknown[] = []
    const { user } = await openEditor('fate', { [put]: recording(bodies, saved([])) })

    await user.click(screen.getByRole('button', { name: 'Add level' }))
    expect(save()).toBeDisabled()
    await replace(user, 'Level 3 key', 'unlikely')
    await replace(user, 'Level 3 label', 'Unlikely')
    await replace(user, 'Level 3 target', '35')
    await user.click(screen.getByRole('button', { name: 'Remove level 1' }))
    await user.click(save())

    await waitFor(() => {
      expect(bodies).toHaveLength(1)
    })
    expect(bodies[0]).toMatchObject({
      oracle: {
        levels: [
          { key: 'likely', label: 'Likely', target: 65 },
          { key: 'unlikely', label: 'Unlikely', target: 35 },
        ],
      },
    })
    await user.click(screen.getByRole('button', { name: 'Remove level 1' }))
    expect(screen.getByRole('button', { name: 'Remove level 1' })).toBeDisabled()
  })

  describe('a refused save', () => {
    it('attaches a message that points at a level to its row, and keeps the edits', async () => {
      const message = 'Likelihood level "x" is invalid at oracles.likelihood[0].levels[1].target.'
      const { user } = await openEditor('fate', { [put]: json({ error: message }, 422) })

      await replace(user, 'Name', 'Luck')
      await replace(user, 'Level 2 target', '500')
      await user.click(save())

      const row = within(await screen.findByRole('listitem', { name: 'Level 2' }))
      expect(row.getByRole('alert')).toHaveTextContent(message)
      expect(screen.getByLabelText('Name')).toHaveValue('Luck')
      expect(screen.getByLabelText('Level 2 target')).toHaveValue('500')
    })

    it('shows the level of another oracle, or any other message, at the form, until the next edit', async () => {
      const message = 'Oracle "likelihood[3].levels[0]" is invalid.'
      const { user } = await openEditor('fate', { [put]: json({ error: message }, 422) })

      await replace(user, 'Name', 'Luck')
      await user.click(save())

      expect(await screen.findByRole('alert')).toHaveTextContent(message)
      const row = within(screen.getByRole('listitem', { name: 'Level 1' }))
      expect(row.queryByRole('alert')).not.toBeInTheDocument()
      await replace(user, 'Name', 'Luck!')
      expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    })

    it('attaches a message about the chaos tracker to its field, and keeps the edits', async () => {
      const stored = withTracker('heat')
      const { user } = await openEditor(
        'fate',
        {
          [put]: json(
            {
              error:
                'oracles.likelihood[0].chaos.tracker: counter "heat" must range 1..9 like the chaos factor, 0..10 given',
            },
            422,
          ),
        },
        stored,
      )

      await replace(user, 'Name', 'Luck')
      await user.click(save())

      const field = screen.getByLabelText('Chaos tracker')
      expect(await screen.findByRole('alert')).toHaveTextContent('must range 1..9')
      expect(field).toBeInvalid()
      expect(field).toHaveValue('heat')
      expect(screen.getByLabelText('Name')).toHaveValue('Luck')
    })

    it('lists where the draft uses the oracle when a rename is refused', async () => {
      const { user } = await openEditor('fate', {
        [put]: json(
          { error: 'Oracle "fate" is used.', paths: ['flows[0].phases[0].steps[1]'] },
          422,
        ),
      })

      await replace(user, 'Key', 'luck')
      await user.click(save())

      const alert = await screen.findByRole('alert')
      expect(alert).toHaveTextContent('Oracle "fate" is used.')
      expect(alert).toHaveTextContent('Used by flows[0].phases[0].steps[1].')
      expect(screen.getByLabelText('Key')).toHaveValue('luck')
    })

    it('says the draft is gone on a 404 and keeps the edits', async () => {
      const { user } = await openEditor('fate', {
        [put]: json({ error: 'GameSystem "my-draft" not found.' }, 404),
      })

      await replace(user, 'Name', 'Luck')
      await user.click(save())

      expect(await screen.findByRole('alert')).toHaveTextContent('not found')
      expect(screen.getByLabelText('Name')).toHaveValue('Luck')
    })

    it('offers to reload on a 409 and resets the form to the latest stored oracle', async () => {
      let reads = 0
      const latest = saved([{ ...fate, definition: { ...fate.definition, name: 'Skies' } }])
      const { user } = await openEditor('fate', {
        [`GET ${base}/oracles`]: () => Response.json(++reads === 1 ? oracles : latest),
        [put]: conflict,
      })

      await replace(user, 'Level 2 label', 'Probable')
      await user.click(save())
      expect(await screen.findByRole('alert')).toHaveTextContent('unsaved edits')
      expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument()
      await user.click(screen.getByRole('button', { name: 'Reload the latest version' }))

      await waitFor(() => {
        expect(screen.getByLabelText('Name')).toHaveValue('Skies')
      })
      expect(screen.getByLabelText('Level 2 label')).toHaveValue('Likely')
      expect(await screen.findByRole('button', { name: 'Save' })).toBeDisabled()
      expect(reads).toBe(2)
    })

    it('keeps the form and says so when the reload fails', async () => {
      let reads = 0
      const { user } = await openEditor('fate', {
        [`GET ${base}/oracles`]: () =>
          ++reads === 1
            ? Response.json(oracles)
            : Response.json({ error: 'Gone.' }, { status: 404 }),
        [put]: conflict,
      })

      await replace(user, 'Level 2 label', 'Probable')
      await user.click(save())
      await user.click(await screen.findByRole('button', { name: 'Reload the latest version' }))

      expect(await screen.findByText(/Couldn.t reload the latest version/)).toBeInTheDocument()
      expect(screen.getByLabelText('Level 2 label')).toHaveValue('Probable')
    })
  })

  it('goes to the new key after a rename, without ever showing the oracle as missing', async () => {
    const renamed = saved([{ ...fate, definition: { ...fate.definition, key: 'luck' } }])
    const { user, router } = await openEditor('fate', { [put]: json(renamed) })
    // The cache answers before a slow router moves the URL.
    const navigate = router.navigate.bind(router)
    vi.spyOn(router, 'navigate').mockImplementation(async (options) => {
      await new Promise((resolve) => setTimeout(resolve, 100))
      await navigate(options)
    })
    let notFound = false
    const observer = new MutationObserver(() => {
      notFound ||= document.body.textContent.includes('no likelihood oracle')
    })
    observer.observe(document.body, { childList: true, subtree: true, characterData: true })

    await replace(user, 'Key', 'luck')
    await user.click(save())

    await waitFor(() => {
      expect(router.state.location.pathname).toBe(
        '/studio/game-systems/my-draft/likelihood-oracles/luck',
      )
    })
    expect(await screen.findByLabelText('Key')).toHaveValue('luck')
    observer.disconnect()
    expect(notFound).toBe(false)
  })
})
