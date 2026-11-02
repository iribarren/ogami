import { act, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { chooseOption, listOptions } from '@/test/chooseOption'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import {
  draft,
  fateAnswer,
  narrativeScenes,
  oracles,
  oraclesV1Likelihood,
  oraclesWithChaos,
  validDraft,
} from '../fixtures'
import { oraclesKey } from './useGameSystemOracles'

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
    const { user } = await openEditor('fate', {}, stored)

    expect(
      (await listOptions(user, screen.getByLabelText('Chaos tracker'))).map(
        (option) => option.label,
      ),
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
    await chooseOption(user, 'Chaos tracker', 'heat')
    await user.click(save())
    await waitFor(() => {
      expect(bodies).toHaveLength(1)
    })
    expect(bodies[0]).toMatchObject({
      oracle: { chaos: { min: 1, max: 9, neutral: 5, shiftPerPoint: 5, tracker: 'heat' } },
    })

    await screen.findByLabelText('Chaos tracker')
    await chooseOption(user, 'Chaos tracker', 'None')
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
    await chooseOption(user, 'Random event table', 'weather')
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

  it('moves a level up or down, offering neither past the ends, and saves the new order', async () => {
    const bodies: unknown[] = []
    const { user } = await openEditor('fate', { [put]: recording(bodies, saved([])) })
    const keys = fate.definition.levels.map(({ key }) => key)

    expect(screen.getByRole('button', { name: 'Move up level 1' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Move down level 2' })).toBeDisabled()
    await user.click(screen.getByRole('button', { name: 'Move down level 1' }))
    expect(screen.getByLabelText('Level 1 key')).toHaveValue(keys[1])
    expect(screen.getByLabelText('Level 2 key')).toHaveValue(keys[0])
    await user.click(save())

    await waitFor(() => {
      expect(bodies).toHaveLength(1)
    })
    expect(bodies[0]).toMatchObject({
      oracle: { levels: [{ key: keys[1] }, { key: keys[0] }] },
    })
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

    it('shows a message about the chaos tracker even when the stored tracker is not text', async () => {
      const stored = withTracker('heat')
      const [oracle] = stored.likelihood as [(typeof stored.likelihood)[number]]
      // A stored tracker the editor cannot edit: not text.
      ;(oracle.definition.chaos as { tracker: unknown }).tracker = 5
      const { user } = await openEditor(
        'fate',
        {
          [put]: json(
            { error: 'oracles.likelihood[0].chaos.tracker: must be a string, 5 given' },
            422,
          ),
        },
        stored,
      )

      await replace(user, 'Name', 'Luck')
      await user.click(save())

      expect(await screen.findByRole('alert')).toHaveTextContent('must be a string')
      expect(screen.getByText('The chaos tracker is not text; it is kept as stored.')).toBeVisible()
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

  it('keeps the form on the renamed key when the navigation is refused, and leaves the URL alone', async () => {
    const renamed = saved([{ ...fate, definition: { ...fate.definition, key: 'luck' } }])
    const { user, router } = await openEditor('fate', { [put]: json(renamed) })
    vi.spyOn(router, 'navigate').mockRejectedValue(new Error('Navigation refused.'))

    await replace(user, 'Key', 'luck')
    await user.click(save())

    // The form is the person's own: it stays, on the key it was saved under, and the URL is left.
    await waitFor(() => {
      expect(router.state.status).toBe('idle')
    })
    expect(screen.getByLabelText('Key')).toHaveValue('luck')
    expect(screen.queryByText(/no likelihood oracle/)).not.toBeInTheDocument()
    expect(router.state.location.pathname).toBe(
      '/studio/game-systems/my-draft/likelihood-oracles/fate',
    )
  })
})

describe('the likelihood oracle editor page', () => {
  const route = '/studio/game-systems/my-draft/likelihood-oracles/fate'
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

    expect(await screen.findByText('Loading the oracle…')).toBeInTheDocument()
    expect(screen.queryByRole('form', { name: 'Likelihood oracle' })).not.toBeInTheDocument()
  })

  it('shows the error, not the form, when the narrative fails to load', async () => {
    renderAppAt(route, handlers(json({ error: 'Narrative down' }, 404)))

    expect(await screen.findByRole('alert')).toHaveTextContent('Narrative down')
    expect(screen.queryByRole('form', { name: 'Likelihood oracle' })).not.toBeInTheDocument()
  })
})

describe('a likelihood form and the version it read', () => {
  const draftRead = {
    [`GET ${base}`]: json(draft),
    [`GET ${base}/validation`]: json(validDraft),
  }
  const changed = { error: 'GameSystem "my-draft" was changed by another request.' }
  const [first, second] = fate.definition.levels as [
    (typeof fate.definition.levels)[number],
    (typeof fate.definition.levels)[number],
  ]
  // Another request saved the oracle meanwhile: newer levels at a newer version.
  const probable = saved([
    {
      ...fate,
      definition: { ...fate.definition, levels: [first, { ...second, label: 'Probable' }] },
    },
  ])

  interface Sent {
    expectedVersion: number
    oracle: Record<string, unknown>
  }
  function recorder(respond: (sent: Sent[]) => Response) {
    const sent: Sent[] = []
    const handler: FakeHandler = async (request) => {
      sent.push((await request.json()) as Sent)
      return respond(sent)
    }
    return { sent, handler }
  }

  /** Reads the oracles again and lets the page take the answer. */
  const refetch = (queryClient: { invalidateQueries: (filters: object) => Promise<void> }) =>
    act(async () => {
      await queryClient.invalidateQueries({ queryKey: oraclesKey('my-draft') })
      await new Promise((resolve) => setTimeout(resolve, 0))
    })
  const dialog = () => screen.queryByRole('dialog', { name: 'Leave without saving?' })
  const back = () => screen.getByRole('link', { name: 'Back to my-draft' })

  it('sends the version it read, so a save after a newer read answers with a conflict', async () => {
    let reads = 0
    const stored = recorder(() => Response.json(changed, { status: 409 }))
    const { user, queryClient } = await openEditor('fate', {
      [`GET ${base}/oracles`]: () => Response.json(++reads === 1 ? oracles : probable),
      [put]: stored.handler,
    })

    await replace(user, 'Name', 'Luck')
    await refetch(queryClient)
    expect(reads).toBe(2)
    await user.click(save())

    await waitFor(() => {
      expect(stored.sent).toHaveLength(1)
    })
    expect(stored.sent[0]?.expectedVersion).toBe(7)
    expect(await screen.findByRole('alert')).toHaveTextContent('changed by another request')
    expect(screen.getByRole('button', { name: 'Reload the latest version' })).toBeInTheDocument()
  })

  it('does not read a clean form as edited when a newer read arrives', async () => {
    let reads = 0
    const { queryClient } = await openEditor('fate', {
      [`GET ${base}/oracles`]: () => Response.json(++reads === 1 ? oracles : probable),
    })

    await refetch(queryClient)

    expect(reads).toBe(2)
    expect(screen.getByLabelText('Level 2 label')).toHaveValue('Likely')
    expect(save()).toBeDisabled()
  })

  it('keeps a draft when its oracle is gone from the cache, and a save says so', async () => {
    const stored = recorder(() => Response.json(changed, { status: 409 }))
    const { user, queryClient } = await openEditor('fate', { [put]: stored.handler })

    await replace(user, 'Name', 'Luck')
    act(() => {
      queryClient.setQueryData(oraclesKey('my-draft'), { ...oracles, version: 8, likelihood: [] })
    })

    expect(screen.getByLabelText('Name')).toHaveValue('Luck')
    await user.click(save())
    expect(await screen.findByRole('alert')).toHaveTextContent('changed by another request')
    expect(stored.sent[0]?.expectedVersion).toBe(7)
  })

  describe('opening from a cache that may be stale', () => {
    /** Opens fate, leaves, and comes back to a stale cache whose read is held until `settle`. */
    async function reopenWhileRefetching(answer: () => Response) {
      let reads = 0
      let release: () => void = () => undefined
      const gate = new Promise<void>((resolve) => {
        release = resolve
      })
      const stored = recorder(() => Response.json(probable))
      const opened = await openEditor('fate', {
        ...draftRead,
        [`GET ${base}/oracles`]: async () => {
          if (++reads === 1) {
            return Response.json(oracles)
          }
          await gate
          return answer()
        },
        [put]: stored.handler,
      })
      await opened.user.click(back())
      await waitFor(() => {
        expect(screen.queryByRole('form', { name: 'Likelihood oracle' })).not.toBeInTheDocument()
      })
      await opened.queryClient.invalidateQueries({
        queryKey: oraclesKey('my-draft'),
        refetchType: 'none',
      })
      await act(() =>
        opened.router.navigate({
          to: '/studio/game-systems/$key/likelihood-oracles/$oracle',
          params: { key: 'my-draft', oracle: 'fate' },
        }),
      )
      return { ...opened, stored, settle: release }
    }

    it('shows the levels the server holds, and saves with its version', async () => {
      const { user, stored, settle } = await reopenWhileRefetching(() => Response.json(probable))

      expect(screen.getByText('Loading the oracle…')).toBeInTheDocument()
      settle()
      await waitFor(() => {
        expect(screen.getByLabelText('Level 2 label')).toHaveValue('Probable')
      })
      await replace(user, 'Name', 'Luck')
      await user.click(save())

      await waitFor(() => {
        expect(stored.sent).toHaveLength(1)
      })
      expect(stored.sent[0]?.expectedVersion).toBe(8)
    })

    it('opens from the cache when the read fails, so a save still answers with a conflict', async () => {
      const { user, stored, settle } = await reopenWhileRefetching(() =>
        Response.json({ error: 'Gone' }, { status: 404 }),
      )

      expect(screen.getByText('Loading the oracle…')).toBeInTheDocument()
      settle()
      expect(await screen.findByRole('form', { name: 'Likelihood oracle' })).toBeInTheDocument()
      expect(screen.getByLabelText('Level 2 label')).toHaveValue('Likely')
      await replace(user, 'Name', 'Luck')
      await user.click(save())

      await waitFor(() => {
        expect(stored.sent).toHaveLength(1)
      })
      expect(stored.sent[0]?.expectedVersion).toBe(7)
    })
  })

  describe('reloading after a conflict', () => {
    it('clears what the test panel last said and keeps its results', async () => {
      let reads = 0
      let asks = 0
      const { user } = await openEditor('fate', {
        [`GET ${base}/oracles`]: () => Response.json(++reads === 1 ? oracles : probable),
        [`POST ${base}/likelihood-oracles/fate/test-answers`]: () =>
          ++asks === 1
            ? Response.json(fateAnswer)
            : Response.json({ error: 'The answer failed.' }, { status: 500 }),
        [put]: json(changed, 409),
      })
      const panel = () => within(screen.getByRole('region', { name: 'Test answer' }))

      await user.click(screen.getByRole('button', { name: 'Ask' }))
      await screen.findByRole('list', { name: 'Test answer results' })
      await user.click(screen.getByRole('button', { name: 'Ask' }))
      await panel().findByRole('alert')
      await replace(user, 'Level 1 label', 'Even')
      await user.click(save())
      await user.click(await screen.findByRole('button', { name: 'Reload the latest version' }))
      await waitFor(() => {
        expect(screen.getByLabelText('Level 2 label')).toHaveValue('Probable')
      })

      expect(panel().queryByRole('alert')).not.toBeInTheDocument()
      expect(panel().getByRole('list', { name: 'Test answer results' })).toBeInTheDocument()
    })

    it('takes the draft, the stored oracle and the version from one read', async () => {
      let reads = 0
      const stored = recorder((sent) =>
        sent.length === 1 ? Response.json(changed, { status: 409 }) : Response.json(probable),
      )
      const { user } = await openEditor('fate', {
        [`GET ${base}/oracles`]: () => Response.json(++reads === 1 ? oracles : probable),
        [put]: stored.handler,
      })

      await replace(user, 'Level 1 label', 'Even')
      await user.click(save())
      await user.click(await screen.findByRole('button', { name: 'Reload the latest version' }))
      await waitFor(() => {
        expect(screen.getByLabelText('Level 2 label')).toHaveValue('Probable')
      })
      expect(screen.getByLabelText('Level 1 label')).toHaveValue('50/50')
      expect(save()).toBeDisabled()
      await replace(user, 'Name', 'Luck')
      await user.click(save())

      await waitFor(() => {
        expect(stored.sent).toHaveLength(2)
      })
      expect(stored.sent.map(({ expectedVersion }) => expectedVersion)).toEqual([7, 8])
    })

    it('keeps the draft and the conflict when the oracle is gone from the latest read', async () => {
      let reads = 0
      const { user } = await openEditor('fate', {
        [`GET ${base}/oracles`]: () =>
          Response.json(++reads === 1 ? oracles : { ...oracles, version: 8, likelihood: [] }),
        [put]: json(changed, 409),
      })

      await replace(user, 'Name', 'Luck')
      await user.click(save())
      await user.click(await screen.findByRole('button', { name: 'Reload the latest version' }))

      expect(await screen.findByText(/no longer exists/)).toBeInTheDocument()
      expect(screen.getByLabelText('Name')).toHaveValue('Luck')
      expect(screen.getByRole('button', { name: 'Reload the latest version' })).toBeInTheDocument()
    })
  })

  describe('leaving', () => {
    it('asks when the form is dirty: Stay keeps the draft, Leave goes', async () => {
      const { user, router } = await openEditor('fate', draftRead)

      await replace(user, 'Name', 'Luck')
      await user.click(back())
      expect(await screen.findByRole('dialog', { name: 'Leave without saving?' })).toBeVisible()
      await user.click(screen.getByRole('button', { name: 'Stay' }))

      await waitFor(() => {
        expect(dialog()).not.toBeInTheDocument()
      })
      expect(screen.getByLabelText('Name')).toHaveValue('Luck')
      expect(router.state.location.pathname).toBe(
        '/studio/game-systems/my-draft/likelihood-oracles/fate',
      )

      await user.click(back())
      await user.click(await screen.findByRole('button', { name: 'Leave without saving' }))
      await waitFor(() => {
        expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft')
      })
    })

    it('leaves a clean form without asking', async () => {
      const { user, router } = await openEditor('fate', draftRead)

      await user.click(back())

      await waitFor(() => {
        expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft')
      })
      expect(dialog()).not.toBeInTheDocument()
    })

    it('does not ask for a rename save, which opens the renamed oracle', async () => {
      const stored = recorder(() =>
        Response.json(saved([{ ...fate, definition: { ...fate.definition, key: 'luck' } }])),
      )
      const { user, router } = await openEditor('fate', { [put]: stored.handler })

      await replace(user, 'Key', 'luck')
      await user.click(save())

      await waitFor(() => {
        expect(router.state.location.pathname).toBe(
          '/studio/game-systems/my-draft/likelihood-oracles/luck',
        )
      })
      expect(dialog()).not.toBeInTheDocument()
    })
  })

  it('carries the version of a test answer’s own save into the next save', async () => {
    const stored = recorder(() => Response.json(saved([fate])))
    const { user } = await openEditor('fate', {
      [put]: stored.handler,
      [`POST ${base}/likelihood-oracles/fate/test-answers`]: json(fateAnswer),
    })

    await replace(user, 'Name', 'Luck')
    await user.click(screen.getByRole('button', { name: 'Ask' }))
    await screen.findByRole('list', { name: 'Test answer results' })
    await replace(user, 'Level 1 label', 'Even')
    await user.click(save())

    await waitFor(() => {
      expect(stored.sent).toHaveLength(2)
    })
    expect(stored.sent.map(({ expectedVersion }) => expectedVersion)).toEqual([7, 8])
  })
})
