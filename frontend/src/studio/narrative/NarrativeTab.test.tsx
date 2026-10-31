import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { chooseOption } from '@/test/chooseOption'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { draft, narrative, narrativeFlows, narrativeV1, validDraft } from '../fixtures'
import { replaceText, typeText } from '@/test/typeText'

const base = '/api/studio/game-systems/my-draft'

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

async function openNarrative(handlers: Record<string, FakeHandler> = {}) {
  const user = userEvent.setup()
  const rendered = renderAppAt('/studio/game-systems/my-draft', {
    'GET /api/auth/me': signedInAs('GAME_MANAGER'),
    'GET /api/studio/game-systems': json([]),
    [`GET ${base}`]: json(draft),
    [`GET ${base}/validation`]: json(validDraft),
    // Flows and Scene Types, so the "Used by" lines can name where an item is used.
    [`GET ${base}/narrative`]: json({
      ...narrative,
      flows: narrativeFlows.flows,
      sceneTypes: narrativeFlows.sceneTypes,
    }),
    ...handlers,
  })
  await user.click(await screen.findByRole('link', { name: 'Trackers & tags' }))
  return { user, ...rendered }
}

const inDialog = () => within(screen.getByRole('dialog'))

/** What a save sent: the request's JSON body. */
interface Sent {
  expectedVersion: number
  item: Record<string, unknown>
}

function recorder(response: Response | (() => Response)) {
  const sent: Sent[] = []
  const handler: FakeHandler = async (request) => {
    sent.push((await request.json()) as Sent)
    return typeof response === 'function' ? response() : response
  }
  return { sent, handler }
}

const changed = {
  error: 'GameSystem "my-draft" was changed by another request. Reload it and try again.',
}

describe('the trackers and tags tab', () => {
  it('lists trackers, tags and fact slots with what they are and where they are used', async () => {
    await openNarrative()

    const trackers = within(await screen.findByRole('region', { name: 'Trackers' }))
    const [heat, alarm] = trackers.getAllByRole('listitem')
    expect(heat).toHaveTextContent('Heat')
    expect(heat).toHaveTextContent('heat')
    expect(heat).toHaveTextContent('Counter 0 to 10')
    expect(heat).toHaveTextContent('2 levels')
    expect(heat).toHaveTextContent('Used by Flows · Heist › Trackers')
    expect(alarm).toHaveTextContent('Clock, 6 segments')
    expect(alarm).toHaveTextContent('Not used')
    const tags = within(screen.getByRole('region', { name: 'Tags' }))
    expect(tags.getAllByRole('listitem')[0]).toHaveTextContent('Gang')
    expect(tags.getAllByRole('listitem')[0]).toHaveTextContent(
      'Used by Scene Types · Ambush › Play › Step 2',
    )
    const slots = within(screen.getByRole('region', { name: 'Fact slots' }))
    expect(slots.getAllByRole('listitem')[0]).toHaveTextContent('Target')
    expect(slots.getAllByRole('listitem')[0]).toHaveTextContent('npc')
    expect(slots.getAllByRole('listitem')[1]).toHaveTextContent('text')
  })

  it('lists a tracker of an unknown kind read-only, with a reason, and still lets it go', async () => {
    await openNarrative()

    const mood = (await screen.findByText('Mood')).closest('li')
    expect(mood).toHaveTextContent("Can't be edited here")
    expect(screen.getByRole('button', { name: 'Edit mood' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Delete mood' })).toBeEnabled()
  })

  it('has empty states', async () => {
    await openNarrative({
      [`GET ${base}/narrative`]: json({ ...narrative, trackers: [], tags: [], factSlots: [] }),
    })

    expect(await screen.findByText('This draft has no trackers yet.')).toBeInTheDocument()
    expect(screen.getByText('This draft has no tags yet.')).toBeInTheDocument()
    expect(screen.getByText('This draft has no fact slots yet.')).toBeInTheDocument()
  })

  it('shows why the narrative could not be loaded', async () => {
    await openNarrative({ [`GET ${base}/narrative`]: () => new Response('Oops', { status: 500 }) })

    expect(await screen.findByRole('alert', {}, { timeout: 3000 })).toHaveTextContent(
      'Loading the narrative failed with HTTP 500.',
    )
  })

  it('says the GameSystem is not found when the narrative is a 404', async () => {
    await openNarrative({ [`GET ${base}/narrative`]: json({ error: 'No GameSystem' }, 404) })

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'This GameSystem does not exist, or it is not one of yours.',
    )
  })

  it('says a schema version 1 draft has no narrative sections and offers no create button', async () => {
    await openNarrative({ [`GET ${base}/narrative`]: json(narrativeV1) })

    expect(await screen.findByText(/have no narrative sections/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^New / })).not.toBeInTheDocument()
    expect(screen.queryByRole('region', { name: 'Trackers' })).not.toBeInTheDocument()
  })
})

describe('deleting', () => {
  it('disables deleting an item the draft uses, and says why', async () => {
    await openNarrative()

    const button = await screen.findByRole('button', { name: 'Delete heat' })
    expect(button).toBeDisabled()
    expect(button).toHaveAccessibleDescription('Used by Flows · Heist › Trackers')
    expect(screen.getByRole('button', { name: 'Delete alarm' })).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Delete gang' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Delete client' })).toBeDisabled()
  })

  it('deletes an unused item with the version it read, and shows the answer', async () => {
    let deleted: URL | undefined
    const { user } = await openNarrative({
      [`DELETE ${base}/narrative/trackers/alarm`]: (request) => {
        deleted = new URL(request.url)
        return Response.json({ ...narrative, version: 6, trackers: narrative.trackers.slice(0, 1) })
      },
    })

    await user.click(await screen.findByRole('button', { name: 'Delete alarm' }))
    await user.click(screen.getByRole('button', { name: 'Delete the tracker' }))

    await waitFor(() => {
      expect(screen.queryByText('Alarm')).not.toBeInTheDocument()
    })
    expect(deleted?.searchParams.get('expectedVersion')).toBe('5')
  })

  it.each([
    ['tags', 'informer', 'Delete the tag'],
    ['fact-slots', 'target', 'Delete the fact slot'],
  ])('deletes from the %s section', async (section, item, confirm) => {
    let path = ''
    const { user } = await openNarrative({
      [`DELETE ${base}/narrative/${section}/${item}`]: (request) => {
        path = new URL(request.url).pathname
        return Response.json(narrative)
      },
    })

    await user.click(await screen.findByRole('button', { name: `Delete ${item}` }))
    await user.click(screen.getByRole('button', { name: confirm }))

    await waitFor(() => {
      expect(path).toBe(`${base}/narrative/${section}/${item}`)
    })
  })

  it('shows where an item is used when the server refuses to delete it', async () => {
    const { user } = await openNarrative({
      [`DELETE ${base}/narrative/trackers/alarm`]: json(
        { error: 'The tracker "alarm" is used.', paths: ['sceneTypes[1].play[2]'] },
        422,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'Delete alarm' }))
    await user.click(screen.getByRole('button', { name: 'Delete the tracker' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'The tracker "alarm" is used. Used by sceneTypes[1].play[2].',
    )
  })

  it('says an item is gone when the server cannot find it', async () => {
    const { user } = await openNarrative({
      [`DELETE ${base}/narrative/trackers/alarm`]: json(
        { error: 'The draft has no tracker "alarm".' },
        404,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'Delete alarm' }))
    await user.click(screen.getByRole('button', { name: 'Delete the tracker' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('The draft has no tracker "alarm".')
  })

  it('offers to reload when the draft changed since it was read', async () => {
    let reads = 0
    const { user } = await openNarrative({
      [`GET ${base}/narrative`]: () =>
        Response.json(
          ++reads === 1
            ? narrative
            : { ...narrative, version: 6, trackers: narrative.trackers.slice(0, 1) },
        ),
      [`DELETE ${base}/narrative/trackers/alarm`]: json(changed, 409),
    })

    await user.click(await screen.findByRole('button', { name: 'Delete alarm' }))
    await user.click(screen.getByRole('button', { name: 'Delete the tracker' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('was changed by another request')
    await user.click(screen.getByRole('button', { name: 'Reload the latest version' }))

    await waitFor(() => {
      expect(screen.queryByText('Alarm')).not.toBeInTheDocument()
    })
    expect(reads).toBe(2)
  })
  it('keeps the delete dialog open with the error when the reload after a conflict fails', async () => {
    let reads = 0
    const { user } = await openNarrative({
      [`GET ${base}/narrative`]: () =>
        ++reads === 1
          ? Response.json(narrative)
          : Response.json({ error: 'Gone' }, { status: 404 }),
      [`DELETE ${base}/narrative/trackers/alarm`]: json(changed, 409),
    })

    await user.click(await screen.findByRole('button', { name: 'Delete alarm' }))
    await user.click(screen.getByRole('button', { name: 'Delete the tracker' }))
    await user.click(await screen.findByRole('button', { name: 'Reload the latest version' }))

    expect(await screen.findByText(/Couldn.t reload the latest version/)).toBeInTheDocument()
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })
})

describe('creating', () => {
  it('creates a counter with levels, whole numbers and the optional hint left out', async () => {
    const saved = recorder(Response.json({ ...narrative, version: 6 }, { status: 201 }))
    const { user } = await openNarrative({ [`POST ${base}/narrative/trackers`]: saved.handler })

    await user.click(await screen.findByRole('button', { name: 'New tracker' }))
    const dialog = inDialog()
    await typeText(user, dialog.getByLabelText('Key'), 'stress')
    await typeText(user, dialog.getByLabelText('Name'), 'Stress')
    await replaceText(user, dialog.getByLabelText('Max'), '6')
    await user.click(dialog.getByRole('button', { name: 'Add level' }))
    await user.click(dialog.getByRole('button', { name: 'Add level' }))
    await typeText(user, dialog.getByLabelText('Level 1 label'), 'Calm')
    await user.type(dialog.getByLabelText('Level 1 up to'), '2')
    await typeText(user, dialog.getByLabelText('Level 2 label'), 'Frayed')
    expect(dialog.queryByLabelText('Level 2 up to')).not.toBeInTheDocument()
    await user.click(dialog.getByRole('button', { name: 'Create tracker' }))

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
    expect(saved.sent).toEqual([
      {
        expectedVersion: 5,
        item: {
          key: 'stress',
          name: 'Stress',
          kind: 'counter',
          min: 0,
          max: 6,
          initial: 0,
          levels: [{ upTo: 2, label: 'Calm' }, { label: 'Frayed' }],
        },
      },
    ])
  })

  it('keeps key, name and hint when the kind changes to a clock', async () => {
    const saved = recorder(Response.json(narrative, { status: 201 }))
    const { user } = await openNarrative({ [`POST ${base}/narrative/trackers`]: saved.handler })

    await user.click(await screen.findByRole('button', { name: 'New tracker' }))
    const dialog = inDialog()
    await typeText(user, dialog.getByLabelText('Key'), 'doom')
    await typeText(user, dialog.getByLabelText('Name'), 'Doom')
    await typeText(user, dialog.getByLabelText('Hint'), 'It comes')
    await chooseOption(user, 'Kind', 'clock', dialog)
    expect(dialog.queryByLabelText('Max')).not.toBeInTheDocument()
    expect(dialog.getByLabelText('Key')).toHaveValue('doom')
    await replaceText(user, dialog.getByLabelText('Segments'), '8')
    await user.click(dialog.getByRole('button', { name: 'Create tracker' }))

    await waitFor(() => {
      expect(saved.sent).toHaveLength(1)
    })
    expect(saved.sent[0]?.item).toEqual({
      key: 'doom',
      name: 'Doom',
      hint: 'It comes',
      kind: 'clock',
      segments: 8,
    })
  })

  it('creates a tag and a fact slot with its type', async () => {
    const tag = recorder(Response.json(narrative, { status: 201 }))
    const slot = recorder(Response.json(narrative, { status: 201 }))
    const { user } = await openNarrative({
      [`POST ${base}/narrative/tags`]: tag.handler,
      [`POST ${base}/narrative/fact-slots`]: slot.handler,
    })

    await user.click(await screen.findByRole('button', { name: 'New tag' }))
    await typeText(user, inDialog().getByLabelText('Key'), 'rival')
    await typeText(user, inDialog().getByLabelText('Label'), 'Rival')
    await user.click(inDialog().getByRole('button', { name: 'Create tag' }))
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })

    await user.click(screen.getByRole('button', { name: 'New fact slot' }))
    await typeText(user, inDialog().getByLabelText('Key'), 'rumour')
    await typeText(user, inDialog().getByLabelText('Label'), 'Rumour')
    await chooseOption(user, 'Type', 'thread', inDialog())
    await user.click(inDialog().getByRole('button', { name: 'Create fact slot' }))

    await waitFor(() => {
      expect(slot.sent).toHaveLength(1)
    })
    expect(tag.sent[0]?.item).toEqual({ key: 'rival', label: 'Rival' })
    expect(slot.sent[0]?.item).toEqual({ key: 'rumour', label: 'Rumour', type: 'thread' })
  })

  it('limits a tag to the kinds ticked under "Applies to", and sends no scope when none or all are', async () => {
    const tag = recorder(() => Response.json(narrative, { status: 201 }))
    const { user } = await openNarrative({ [`POST ${base}/narrative/tags`]: tag.handler })

    const create = async (key: string, kinds: string[]) => {
      await user.click(await screen.findByRole('button', { name: 'New tag' }))
      await typeText(user, inDialog().getByLabelText('Key'), key)
      await typeText(user, inDialog().getByLabelText('Label'), key)
      const group = inDialog().getByRole('group', { name: 'Applies to' })
      for (const kind of kinds) {
        await user.click(within(group).getByRole('checkbox', { name: kind }))
      }
      await user.click(inDialog().getByRole('button', { name: 'Create tag' }))
      await waitFor(() => {
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
      })
    }
    await create('villain', ['NPCs', 'Threads'])
    await create('anyone', [])
    await create('everyone', ['Characters', 'NPCs', 'Threads', 'Places'])
    await create('where', ['Places'])

    expect(tag.sent.map((request) => request.item)).toEqual([
      { key: 'villain', label: 'villain', appliesTo: ['npc', 'thread'] },
      { key: 'anyone', label: 'anyone' },
      { key: 'everyone', label: 'everyone' },
      { key: 'where', label: 'where', appliesTo: ['place'] },
    ])
  })

  it('shows the kinds a stored tag is limited to', async () => {
    const scoped = {
      ...narrative,
      tags: [
        {
          key: 'informer',
          definition: { key: 'informer', label: 'Informer', appliesTo: ['npc'] },
          usedBy: [],
        },
      ],
    }
    const { user } = await openNarrative({ [`GET ${base}/narrative`]: () => Response.json(scoped) })

    await user.click(await screen.findByRole('button', { name: 'Edit informer' }))

    const group = inDialog().getByRole('group', { name: 'Applies to' })
    expect(within(group).getByRole('checkbox', { name: 'NPCs' })).toBeChecked()
    expect(within(group).getByRole('checkbox', { name: 'Characters' })).not.toBeChecked()
    expect(within(group).getByRole('checkbox', { name: 'Threads' })).not.toBeChecked()
  })

  it('flags a number that is not whole and sends nothing', async () => {
    const saved = recorder(Response.json(narrative, { status: 201 }))
    const { user } = await openNarrative({ [`POST ${base}/narrative/trackers`]: saved.handler })

    await user.click(await screen.findByRole('button', { name: 'New tracker' }))
    await typeText(user, inDialog().getByLabelText('Key'), 'stress')
    await typeText(user, inDialog().getByLabelText('Name'), 'Stress')
    await replaceText(user, inDialog().getByLabelText('Max'), '2.5')
    await user.click(inDialog().getByRole('button', { name: 'Create tracker' }))

    expect(inDialog().getByLabelText('Max')).toBeInvalid()
    expect(inDialog().getByText('Use a whole number.')).toBeInTheDocument()
    expect(saved.sent).toEqual([])
  })

  it('keeps the dialog and what was typed when the server refuses, and shows its message', async () => {
    const saved = recorder(
      Response.json({ error: 'trackers[3].max: must be at least min' }, { status: 422 }),
    )
    const { user } = await openNarrative({ [`POST ${base}/narrative/tags`]: saved.handler })

    await user.click(await screen.findByRole('button', { name: 'New tag' }))
    await typeText(user, inDialog().getByLabelText('Key'), 'Bad Key')
    await typeText(user, inDialog().getByLabelText('Label'), 'Rival')
    await user.click(inDialog().getByRole('button', { name: 'Create tag' }))

    expect(await inDialog().findByRole('alert')).toHaveTextContent(
      'trackers[3].max: must be at least min',
    )
    expect(inDialog().getByLabelText('Key')).toHaveValue('Bad Key')
    expect(inDialog().getByLabelText('Label')).toHaveValue('Rival')
  })

  it('reloads the latest version after a conflict and then saves with it', async () => {
    let reads = 0
    let attempts = 0
    const saved = recorder(() =>
      ++attempts === 1
        ? Response.json(changed, { status: 409 })
        : Response.json(narrative, { status: 201 }),
    )
    const { user } = await openNarrative({
      [`GET ${base}/narrative`]: () =>
        Response.json(++reads === 1 ? narrative : { ...narrative, version: 9 }),
      [`POST ${base}/narrative/tags`]: saved.handler,
    })

    await user.click(await screen.findByRole('button', { name: 'New tag' }))
    await typeText(user, inDialog().getByLabelText('Key'), 'rival')
    await typeText(user, inDialog().getByLabelText('Label'), 'Rival')
    await user.click(inDialog().getByRole('button', { name: 'Create tag' }))
    expect(await inDialog().findByRole('alert')).toHaveTextContent('was changed by another request')
    await user.click(inDialog().getByRole('button', { name: 'Reload the latest version' }))
    await waitFor(() => {
      expect(inDialog().queryByRole('alert')).not.toBeInTheDocument()
    })
    await user.click(inDialog().getByRole('button', { name: 'Create tag' }))

    await waitFor(() => {
      expect(saved.sent).toHaveLength(2)
    })
    expect(saved.sent.map((request) => request.expectedVersion)).toEqual([5, 9])
    expect(saved.sent[1]?.item).toEqual({ key: 'rival', label: 'Rival' })
  })
})

describe('editing', () => {
  it('opens a tracker with its stored values, levels included', async () => {
    const { user } = await openNarrative()

    await user.click(await screen.findByRole('button', { name: 'Edit heat' }))

    const dialog = inDialog()
    expect(dialog.getByRole('heading', { name: 'Edit heat' })).toBeInTheDocument()
    expect(dialog.getByLabelText('Key')).toHaveValue('heat')
    expect(dialog.getByLabelText('Hint')).toHaveValue('How closely the law is looking')
    expect(dialog.getByLabelText('Initial')).toHaveValue('2')
    expect(dialog.getByLabelText('Level 1 label')).toHaveValue('Quiet')
    expect(dialog.getByLabelText('Level 1 up to')).toHaveValue('3')
    expect(dialog.getByLabelText('Level 2 label')).toHaveValue('Burning')
  })

  it('renames through PUT with the original key and keeps what the editor does not know', async () => {
    const stored = {
      ...narrative,
      trackers: [
        {
          ...narrative.trackers[1],
          definition: { key: 'alarm', name: 'Alarm', kind: 'clock', segments: 6, theme: 'red' },
        },
      ],
    }
    const saved = recorder(Response.json({ ...stored, version: 6 }))
    const { user } = await openNarrative({
      [`GET ${base}/narrative`]: json(stored),
      [`PUT ${base}/narrative/trackers/alarm`]: saved.handler,
    })

    await user.click(await screen.findByRole('button', { name: 'Edit alarm' }))
    await replaceText(user, inDialog().getByLabelText('Key'), 'siren')
    await user.click(inDialog().getByRole('button', { name: 'Save tracker' }))

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
    expect(saved.sent).toEqual([
      {
        expectedVersion: 5,
        item: { key: 'siren', name: 'Alarm', kind: 'clock', segments: 6, theme: 'red' },
      },
    ])
  })

  it('saves with the version the dialog was opened at, so a change made meanwhile still gets a 409', async () => {
    let reads = 0
    const saved = recorder(Response.json(changed, { status: 409 }))
    const { user, queryClient } = await openNarrative({
      [`GET ${base}/narrative`]: () =>
        Response.json(++reads === 1 ? narrative : { ...narrative, version: 6 }),
      [`PUT ${base}/narrative/tags/informer`]: saved.handler,
    })

    await user.click(await screen.findByRole('button', { name: 'Edit informer' }))
    await typeText(user, inDialog().getByLabelText('Hint'), 'Knows the docks')
    await queryClient.refetchQueries({
      queryKey: ['studio', 'game-systems', 'my-draft', 'narrative'],
    })
    await user.click(inDialog().getByRole('button', { name: 'Save tag' }))

    expect(await inDialog().findByRole('alert')).toHaveTextContent('was changed by another request')
    expect(reads).toBe(2)
    expect(saved.sent.map((request) => request.expectedVersion)).toEqual([5])
  })

  it('takes the reloaded item and its version together after a conflict', async () => {
    let reads = 0
    let attempts = 0
    const reloaded = {
      ...narrative,
      version: 7,
      tags: [
        narrative.tags[0],
        {
          key: 'informer',
          definition: { key: 'informer', label: 'Snitch', hint: 'Changed elsewhere' },
          usedBy: [],
        },
      ],
    }
    const saved = recorder(() =>
      ++attempts === 1 ? Response.json(changed, { status: 409 }) : Response.json(reloaded),
    )
    const { user } = await openNarrative({
      [`GET ${base}/narrative`]: () => Response.json(++reads === 1 ? narrative : reloaded),
      [`PUT ${base}/narrative/tags/informer`]: saved.handler,
    })

    await user.click(await screen.findByRole('button', { name: 'Edit informer' }))
    await typeText(user, inDialog().getByLabelText('Hint'), 'Mine')
    await user.click(inDialog().getByRole('button', { name: 'Save tag' }))
    await user.click(await inDialog().findByRole('button', { name: 'Reload the latest version' }))

    await waitFor(() => {
      expect(inDialog().getByLabelText('Label')).toHaveValue('Snitch')
    })
    expect(inDialog().getByLabelText('Hint')).toHaveValue('Changed elsewhere')
    await user.click(inDialog().getByRole('button', { name: 'Save tag' }))
    await waitFor(() => {
      expect(saved.sent).toHaveLength(2)
    })
    expect(saved.sent.map((request) => request.expectedVersion)).toEqual([5, 7])
  })

  it('says so when the reload after a conflict fails, and keeps the typed form', async () => {
    let reads = 0
    const saved = recorder(Response.json(changed, { status: 409 }))
    const { user } = await openNarrative({
      [`GET ${base}/narrative`]: () =>
        ++reads === 1
          ? Response.json(narrative)
          : Response.json({ error: 'Gone' }, { status: 404 }),
      [`PUT ${base}/narrative/tags/informer`]: saved.handler,
    })

    await user.click(await screen.findByRole('button', { name: 'Edit informer' }))
    await typeText(user, inDialog().getByLabelText('Hint'), 'Mine')
    await user.click(inDialog().getByRole('button', { name: 'Save tag' }))
    await user.click(await inDialog().findByRole('button', { name: 'Reload the latest version' }))

    expect(await inDialog().findByText(/Couldn.t reload the latest version/)).toBeInTheDocument()
    expect(inDialog().getByLabelText('Hint')).toHaveValue('Mine')
  })

  it('keeps the old version and the form when the reloaded item cannot be read, so a later save still conflicts', async () => {
    let reads = 0
    const saved = recorder(Response.json(changed, { status: 409 }))
    const unreadable = [
      narrative.tags[0],
      { key: 'informer', definition: { key: 'informer', label: 7 }, usedBy: [] },
    ]
    const { user } = await openNarrative({
      [`GET ${base}/narrative`]: () =>
        Response.json(++reads === 1 ? narrative : { ...narrative, version: 8, tags: unreadable }),
      [`PUT ${base}/narrative/tags/informer`]: saved.handler,
    })

    await user.click(await screen.findByRole('button', { name: 'Edit informer' }))
    await typeText(user, inDialog().getByLabelText('Hint'), 'Mine')
    await user.click(inDialog().getByRole('button', { name: 'Save tag' }))
    await user.click(await inDialog().findByRole('button', { name: 'Reload the latest version' }))

    expect(await inDialog().findByText(/cannot read/)).toBeInTheDocument()
    expect(inDialog().getByLabelText('Hint')).toHaveValue('Mine')
    await user.click(inDialog().getByRole('button', { name: 'Save tag' }))
    await waitFor(() => {
      expect(saved.sent).toHaveLength(2)
    })
    expect(saved.sent.map((request) => request.expectedVersion)).toEqual([5, 5])
  })

  it('adds, moves and deletes levels', async () => {
    const saved = recorder(Response.json(narrative))
    const { user } = await openNarrative({ [`PUT ${base}/narrative/trackers/heat`]: saved.handler })

    await user.click(await screen.findByRole('button', { name: 'Edit heat' }))
    const dialog = inDialog()
    await user.click(dialog.getByRole('button', { name: 'Add level' }))
    await typeText(user, dialog.getByLabelText('Level 3 label'), 'Inferno')
    await user.click(dialog.getByRole('button', { name: 'Move level 3 up' }))
    expect(dialog.getByLabelText('Level 2 label')).toHaveValue('Inferno')
    expect(dialog.getByRole('button', { name: 'Move level 1 up' })).toBeDisabled()
    await user.click(dialog.getByRole('button', { name: 'Move level 2 down' }))
    await user.click(dialog.getByRole('button', { name: 'Delete level 1' }))
    await user.click(dialog.getByRole('button', { name: 'Save tracker' }))

    await waitFor(() => {
      expect(saved.sent).toHaveLength(1)
    })
    expect(saved.sent[0]?.item.levels).toEqual([{ label: 'Burning' }, { label: 'Inferno' }])
  })

  it('opens a legacy counter with no hint and no levels, and a tag or slot with no hint', async () => {
    const legacy = {
      ...narrative,
      trackers: [
        {
          key: 'edge',
          definition: { key: 'edge', name: 'Edge', kind: 'counter', min: 0, max: 5, initial: 0 },
          usedBy: [],
        },
      ],
    }
    const { user } = await openNarrative({ [`GET ${base}/narrative`]: json(legacy) })

    expect(await screen.findByText('Edge')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Edit edge' }))
    expect(inDialog().getByLabelText('Hint')).toHaveValue('')
    expect(inDialog().queryByLabelText('Level 1 label')).not.toBeInTheDocument()
  })

  it('keeps the dialog when the server refuses an edit, naming the path', async () => {
    const { user } = await openNarrative({
      [`PUT ${base}/narrative/tags/gang`]: json(
        { error: 'The tag "gang" is used.', paths: ['sceneTypes[0].play[1]'] },
        422,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'Edit gang' }))
    await replaceText(user, inDialog().getByLabelText('Key'), 'crew')
    await user.click(inDialog().getByRole('button', { name: 'Save tag' }))

    expect(await inDialog().findByRole('alert')).toHaveTextContent(
      'The tag "gang" is used. Used by sceneTypes[0].play[1].',
    )
    expect(inDialog().getByLabelText('Key')).toHaveValue('crew')
  })

  it('says the item is gone when the server cannot find it, and keeps the dialog', async () => {
    const { user } = await openNarrative({
      [`PUT ${base}/narrative/fact-slots/target`]: json(
        { error: 'The draft has no fact slot "target".' },
        404,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'Edit target' }))
    await user.click(inDialog().getByRole('button', { name: 'Save fact slot' }))

    expect(await inDialog().findByRole('alert')).toHaveTextContent(
      'The draft has no fact slot "target".',
    )
  })

  it('shows an unexpected failure with a generic message', async () => {
    const { user } = await openNarrative({
      [`PUT ${base}/narrative/tags/gang`]: () => new Response('Oops', { status: 500 }),
    })

    await user.click(await screen.findByRole('button', { name: 'Edit gang' }))
    await user.click(inDialog().getByRole('button', { name: 'Save tag' }))

    expect(await inDialog().findByRole('alert')).toHaveTextContent(
      'Saving the item failed with HTTP 500.',
    )
  })
})
