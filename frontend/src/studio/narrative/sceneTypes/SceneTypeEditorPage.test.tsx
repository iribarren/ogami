import { QueryClientProvider } from '@tanstack/react-query'
import { createBrowserHistory, RouterProvider } from '@tanstack/react-router'
import { act, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { createQueryClient } from '@/app/queryClient'
import { createAppRouter } from '@/app/router'
import { ApiClientProvider } from '@/shared/api/ApiClientProvider'
import { ColorModeProvider } from '@/shared/theme/ColorModeProvider'
import { createFakeApiClient, type FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { ambush, draft, narrativeScenes, validDraft } from '../../fixtures'
import { openStepCards } from '@/test/openStepCards'
import { narrativeKey } from '../useGameSystemNarrative'

// The step cards' Requirement radios sit inside this page's form, where Radix measures them.
vi.stubGlobal(
  'ResizeObserver',
  class {
    observe = vi.fn()
    unobserve = vi.fn()
    disconnect = vi.fn()
  },
)

const base = '/api/studio/game-systems/my-draft'
const put = `PUT ${base}/narrative/scene-types/ambush`

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

async function openEditor(scene: string, handlers: Record<string, FakeHandler> = {}) {
  const user = userEvent.setup()
  const rendered = renderAppAt(`/studio/game-systems/my-draft/scene-types/${scene}`, {
    'GET /api/auth/me': signedInAs('GAME_MANAGER'),
    [`GET ${base}/narrative`]: json(narrativeScenes),
    ...handlers,
  })
  await screen.findByRole('form', { name: 'Scene Type' })
  // Step cards start collapsed: open them, as a person does, before editing their fields.
  openStepCards()
  return { user, ...rendered }
}

const list = (name: string) => within(screen.getByRole('region', { name }))
const card = (name: string, number: number) =>
  within(list(name).getByRole('group', { name: `Step ${String(number)}` }))
/** The Scene Type's own key: every step card has a Key of its own too. */
function sceneKey() {
  const [first] = screen.getAllByLabelText('Key')
  if (!first) {
    throw new Error('No key field.')
  }
  return first
}
const save = () => screen.getByRole('button', { name: 'Save' })

async function replace(user: ReturnType<typeof userEvent.setup>, field: HTMLElement, text: string) {
  await user.clear(field)
  await user.type(field, text)
}

/** What a save sent: the request's JSON body. */
interface Sent {
  expectedVersion: number
  item: Record<string, unknown>
}

function recorder(respond: (sent: Sent[]) => Response) {
  const sent: Sent[] = []
  const handler: FakeHandler = async (request) => {
    sent.push((await request.json()) as Sent)
    return respond(sent)
  }
  return { sent, handler }
}

// The page message above the form; a refused save also puts "Not saved: n errors" in an alert beside Save.
const pageAlert = async () => (await screen.findAllByRole('alert'))[0]

const withScene = (definition: Record<string, unknown>, version = 6) => ({
  ...narrativeScenes,
  version,
  sceneTypes: [
    { key: String(definition.key), definition, usedBy: [] },
    ...narrativeScenes.sceneTypes.slice(1),
  ],
})

const changed = {
  error: 'GameSystem "my-draft" was changed by another request. Reload it and try again.',
}

describe('the Scene Type editor', () => {
  it('shows the section rail with Scene Types active and the GameSystem name above the title', async () => {
    await openEditor('ambush', { [`GET ${base}`]: json(draft) })

    const rail = await screen.findByRole('navigation', { name: 'GameSystem page' })
    expect(within(rail).getByRole('link', { name: 'Scene Types' })).toHaveAttribute(
      'aria-current',
      'page',
    )
    expect(within(rail).getByRole('link', { name: 'Overview' })).not.toHaveAttribute('aria-current')
    expect(screen.getByText('My draft · Scene Type')).toBeInTheDocument()
  })

  it('still shows the editor, without the rail, while the GameSystem is unavailable', async () => {
    const { fakeApi } = await openEditor('ambush', {
      [`GET ${base}`]: json({ error: 'Nope.' }, 500),
    })
    // The 500 has to come back before the rail is judged absent.
    await waitFor(() => {
      expect(fakeApi.requests).toContain(`GET ${base}`)
    })

    expect(screen.getByText('my-draft · Scene Type')).toBeInTheDocument()
    expect(screen.queryByRole('navigation', { name: 'GameSystem page' })).not.toBeInTheDocument()
  })

  it('shows the fields, the oracle shortcuts and the three step lists of the Scene Type', async () => {
    await openEditor('ambush')

    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Ambush')
    expect(sceneKey()).toHaveValue('ambush')
    expect(screen.getByLabelText('Name')).toHaveValue('Ambush')
    expect(screen.getByLabelText('Purpose')).toHaveValue('Something goes wrong on the street.')
    expect(screen.getByLabelText('Tips')).toHaveValue('Keep it short.')
    expect(
      within(screen.getByRole('group', { name: 'Oracle shortcuts' })).getByRole('checkbox', {
        name: 'Fate',
      }),
    ).toBeChecked()
    expect(
      within(screen.getByRole('group', { name: 'Oracle shortcuts' })).getByRole('checkbox', {
        name: 'Complications',
      }),
    ).toBeChecked()
    expect(
      within(screen.getByRole('group', { name: 'Oracle shortcuts' })).getByRole('checkbox', {
        name: 'Places',
      }),
    ).not.toBeChecked()
    expect(card('Setup', 1).getByLabelText('Title')).toHaveValue('Arrive')
    expect(card('Play', 2).getByLabelText('Title')).toHaveValue('React')
    expect(card('Closing', 1).getByLabelText('Title')).toHaveValue('Aftermath')
    expect(screen.getByRole('link', { name: 'Back to my-draft' })).toHaveAttribute(
      'href',
      '/studio/game-systems/my-draft',
    )
  })

  it('saves an edited field and step with the original key and the version it read, unknown fields kept', async () => {
    const saved = recorder(() => Response.json(withScene({ ...ambush, name: 'Trap' })))
    const { user } = await openEditor('ambush', { [put]: saved.handler })

    await replace(user, screen.getByLabelText('Name'), 'Trap')
    await replace(user, card('Play', 2).getByLabelText('Title'), 'Run')
    await user.click(
      within(screen.getByRole('group', { name: 'Oracle shortcuts' })).getByRole('checkbox', {
        name: 'Places',
      }),
    )
    await user.click(save())

    await waitFor(() => {
      expect(saved.sent).toHaveLength(1)
    })
    const [first, second] = ambush.play
    expect(saved.sent[0]).toEqual({
      expectedVersion: 5,
      item: {
        ...ambush,
        name: 'Trap',
        oracles: ['fate', 'complications', 'places'],
        play: [first, { ...second, title: 'Run' }],
      },
    })
  })

  it('confirms a save with the time and flags later edits as unsaved', async () => {
    const saved = recorder(() => Response.json(withScene({ ...ambush, name: 'Trap' })))
    const { user } = await openEditor('ambush', { [put]: saved.handler })
    expect(screen.queryByText(/Unsaved changes|Saved at|Not saved/)).not.toBeInTheDocument()

    await replace(user, screen.getByLabelText('Name'), 'Trap')
    expect(screen.getByText('Unsaved changes')).toBeInTheDocument()
    await user.click(save())
    expect(await screen.findByRole('status')).toHaveTextContent(/^Saved at/)

    await replace(user, screen.getByLabelText('Name'), 'Trap again')
    expect(screen.getByText('Unsaved changes')).toBeInTheDocument()
  })

  it('sends the version of the last save with the next one', async () => {
    const saved = recorder((sent) =>
      Response.json(withScene({ ...ambush, name: `Trap ${String(sent.length)}` }, 5 + sent.length)),
    )
    const { user } = await openEditor('ambush', { [put]: saved.handler })

    await replace(user, screen.getByLabelText('Name'), 'Trap 1')
    await user.click(save())
    await waitFor(() => {
      expect(saved.sent).toHaveLength(1)
    })
    await replace(user, screen.getByLabelText('Name'), 'Trap 2')
    await user.click(save())

    await waitFor(() => {
      expect(saved.sent).toHaveLength(2)
    })
    expect(saved.sent.map(({ expectedVersion }) => expectedVersion)).toEqual([5, 6])
  })

  it('opens the editor of the renamed Scene Type after a rename, and keeps the form', async () => {
    const renamed = { ...ambush, key: 'trap' }
    const saved = recorder(() => Response.json(withScene(renamed)))
    const { user, router } = await openEditor('ambush', { [put]: saved.handler })

    await replace(user, sceneKey(), 'trap')
    await user.click(save())

    await waitFor(() => {
      expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft/scene-types/trap')
    })
    expect(saved.sent[0]?.item).toMatchObject({ key: 'trap' })
    expect(sceneKey()).toHaveValue('trap')
    expect(screen.queryByText(/has no Scene Type/)).not.toBeInTheDocument()
  })

  it('shows "Saved at" on the renamed editor after a rename save', async () => {
    const saved = recorder(() => Response.json(withScene({ ...ambush, key: 'trap' })))
    const { user, router } = await openEditor('ambush', { [put]: saved.handler })

    await replace(user, sceneKey(), 'trap')
    await user.click(save())

    await waitFor(() => {
      expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft/scene-types/trap')
    })
    expect(await screen.findByRole('status')).toHaveTextContent(/^Saved at/)
  })

  it('sends the version of the save that renamed it with the next save, to the new key', async () => {
    const renamed = { ...ambush, key: 'trap' }
    const renamedSave = recorder(() => Response.json(withScene(renamed, 9)))
    const nextSave = recorder(() => Response.json(withScene(renamed, 10)))
    const { user, router } = await openEditor('ambush', {
      [put]: renamedSave.handler,
      [`PUT ${base}/narrative/scene-types/trap`]: nextSave.handler,
    })

    await replace(user, sceneKey(), 'trap')
    await user.click(save())
    await waitFor(() => {
      expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft/scene-types/trap')
    })
    await replace(user, screen.getByLabelText('Name'), 'Trap again')
    await user.click(save())

    await waitFor(() => {
      expect(nextSave.sent).toHaveLength(1)
    })
    expect(renamedSave.sent[0]?.expectedVersion).toBe(5)
    expect(nextSave.sent[0]?.expectedVersion).toBe(9)
  })

  it('reads a legacy Scene Type with no tips and does not invent them on save', async () => {
    const saved = recorder(() => Response.json(narrativeScenes))
    const { user } = await openEditor('quiet', {
      [`PUT ${base}/narrative/scene-types/quiet`]: saved.handler,
    })

    expect(screen.getByLabelText('Tips')).toHaveValue('')
    await user.click(save())

    await waitFor(() => {
      expect(saved.sent).toHaveLength(1)
    })
    expect(saved.sent[0]?.item).toEqual({
      key: 'quiet',
      name: 'Quiet',
      purpose: 'Catch a breath.',
      oracles: [],
      setup: [],
      play: [],
      closing: [],
    })
  })

  it('toggles an oracle shortcut by its pill, and the pill is still a checkbox', async () => {
    const { user } = await openEditor('ambush')
    const shortcuts = within(screen.getByRole('group', { name: 'Oracle shortcuts' }))

    await user.click(shortcuts.getByText('Places'))
    expect(shortcuts.getByRole('checkbox', { name: 'Places' })).toBeChecked()
    await user.click(shortcuts.getByText('Fate'))
    expect(shortcuts.getByRole('checkbox', { name: 'Fate' })).not.toBeChecked()
    // Keyboard works as on any checkbox.
    shortcuts.getByRole('checkbox', { name: 'Fate' }).focus()
    await user.keyboard(' ')
    expect(shortcuts.getByRole('checkbox', { name: 'Fate' })).toBeChecked()
  })

  it('counts the steps of each part beside its name, which stays the name of the part', async () => {
    await openEditor('ambush')

    expect(list('Setup').getByRole('heading', { name: 'Setup' })).toBeInTheDocument()
    expect(screen.getByRole('region', { name: 'Setup' })).toHaveAccessibleDescription('1')
    expect(screen.getByRole('region', { name: 'Play' })).toHaveAccessibleDescription('2')
    expect(screen.getByRole('region', { name: 'Closing' })).toHaveAccessibleDescription('1')
  })

  it('puts the details and the steps in their own panels', async () => {
    await openEditor('ambush')

    expect(screen.getByRole('region', { name: 'Details' })).toContainElement(sceneKey())
    expect(screen.getByRole('region', { name: 'Steps' })).toContainElement(
      screen.getByRole('region', { name: 'Setup' }),
    )
  })

  it('shows the message about the shortcuts on them', async () => {
    const { user } = await openEditor('ambush', {
      [put]: json({ error: 'sceneTypes[0].oracles: unknown oracle "fate"' }, 422),
    })

    await user.click(save())

    const shortcuts = screen.getByRole('group', { name: 'Oracle shortcuts' })
    expect(await within(shortcuts).findByText(/unknown oracle/)).toBeInTheDocument()
    expect(shortcuts).toHaveAccessibleDescription(/unknown oracle/)
  })

  it('adds a step to a list and saves it in that list only', async () => {
    const saved = recorder(() => Response.json(narrativeScenes))
    const { user } = await openEditor('quiet', {
      [`PUT ${base}/narrative/scene-types/quiet`]: saved.handler,
    })

    await user.click(list('Closing').getByRole('button', { name: 'Add step' }))
    await user.click(await screen.findByRole('menuitem', { name: 'prompt' }))
    await replace(user, card('Closing', 1).getByLabelText('Title'), 'Wrap up')
    await user.click(save())

    await waitFor(() => {
      expect(saved.sent).toHaveLength(1)
    })
    expect(saved.sent[0]?.item).toMatchObject({
      setup: [],
      play: [],
      closing: [{ kind: 'prompt', title: 'Wrap up' }],
    })
  })

  it('offers a prompt step a sheet effect in Play and Closing, but not in Setup', async () => {
    const { user } = await openEditor('quiet')
    const sheetOffered = async (name: string) => {
      await user.click(list(name).getByRole('button', { name: 'Add step' }))
      await user.click(await screen.findByRole('menuitem', { name: 'prompt' }))
      const kind = card(name, 1).getByLabelText('New effect kind')
      await user.click(kind)
      const offered = within(await screen.findByRole('listbox'))
        .getAllByRole('option')
        .map((option) => option.getAttribute('data-option-value'))
      await user.keyboard('{Escape}')
      return offered.includes('sheet')
    }

    expect(await sheetOffered('Setup')).toBe(false)
    expect(await sheetOffered('Play')).toBe(true)
    expect(await sheetOffered('Closing')).toBe(true)
  })

  it('shows a value that is not a whole number next to it and sends nothing from any list', async () => {
    const saved = recorder(() => Response.json(narrativeScenes))
    const { user } = await openEditor('ambush', { [put]: saved.handler })

    await replace(user, screen.getByLabelText('Name'), 'Trap')
    await user.click(
      within(card('Closing', 1).getByRole('group', { name: 'Effects' })).getByRole('button', {
        name: 'Add effect',
      }),
    )
    const effects = within(card('Closing', 1).getByRole('group', { name: 'Effects' }))
    await replace(user, effects.getByLabelText('Value'), '1.5')
    await user.click(save())

    expect(saved.sent).toHaveLength(0)
    expect(effects.getByText('Use a whole number.')).toBeInTheDocument()

    await replace(user, effects.getByLabelText('Value'), '2')
    await user.click(save())

    await waitFor(() => {
      expect(saved.sent).toHaveLength(1)
    })
    expect(screen.queryByText('Use a whole number.')).not.toBeInTheDocument()
  })
})

describe('a refused save', () => {
  it('puts a message about a step on the card of the list it names', async () => {
    const { user } = await openEditor('ambush', {
      [put]: json({ error: 'sceneTypes[0].closing[0].title: must not be blank' }, 422),
    })

    await user.click(save())

    expect(await card('Closing', 1).findByText('must not be blank')).toBeInTheDocument()
    expect(list('Setup').queryByText('must not be blank')).not.toBeInTheDocument()
    expect(list('Play').queryByText('must not be blank')).not.toBeInTheDocument()
    expect(screen.getByText('Not saved: 1 error')).toBeInTheDocument()
  })

  it.each([
    ['setup', 1, 'arrive'],
    ['play', 2, 'react'],
  ])('puts a message about a %s step on its card', async (part, number, key) => {
    const { user } = await openEditor('ambush', {
      [put]: json({ error: `sceneTypes[0].${part}[${String(number - 1)}].key: bad ${key}` }, 422),
    })

    await user.click(save())

    const name = part === 'setup' ? 'Setup' : 'Play'
    expect(await card(name, number).findByText(`bad ${key}`)).toBeInTheDocument()
  })

  it('shows a list-level message at the head of its list', async () => {
    const { user } = await openEditor('ambush', {
      [put]: json({ error: 'sceneTypes[0].play: at most 50 steps' }, 422),
    })

    await user.click(save())

    expect(await list('Play').findByRole('alert')).toHaveTextContent(
      'sceneTypes[0].play: at most 50 steps',
    )
  })

  it('puts a message about a field next to it', async () => {
    const { user } = await openEditor('ambush', {
      [put]: json({ error: 'sceneTypes[0].purpose: must be at most 500 characters' }, 422),
    })

    await user.click(save())

    expect(await screen.findByText('must be at most 500 characters')).toBeInTheDocument()
    // Only the save state: a field message needs no page alert.
    expect(screen.getByRole('alert')).toHaveTextContent('Not saved: 1 error')
  })

  it('shows any other message on the page, with its path', async () => {
    const { user } = await openEditor('ambush', {
      [put]: json({ error: 'flows[0].phases[0].selection: unknown Scene Type "x"' }, 422),
    })

    await user.click(save())

    expect(await pageAlert()).toHaveTextContent(
      'flows[0].phases[0].selection: unknown Scene Type "x"',
    )
  })

  it('says the Scene Type is gone when the server cannot find it', async () => {
    const { user } = await openEditor('ambush', {
      [put]: json({ error: 'The draft has no Scene Type "ambush".' }, 404),
    })

    await user.click(save())

    expect(await pageAlert()).toHaveTextContent('The draft has no Scene Type "ambush".')
  })
})

describe('a conflict', () => {
  const newer = { ...ambush, purpose: 'Changed elsewhere.' }

  it('takes the new version only together with the replaced draft, and saves with it', async () => {
    let reads = 0
    const saved = recorder((sent) =>
      sent.length === 1
        ? Response.json(changed, { status: 409 })
        : Response.json(withScene(newer, 7)),
    )
    const { user } = await openEditor('ambush', {
      [`GET ${base}/narrative`]: () =>
        Response.json(++reads === 1 ? narrativeScenes : withScene(newer, 6)),
      [put]: saved.handler,
    })

    await replace(user, screen.getByLabelText('Name'), 'Mine')
    await user.click(save())
    expect(await pageAlert()).toHaveTextContent('was changed by another request')
    await user.click(screen.getByRole('button', { name: 'Reload the latest version' }))

    await waitFor(() => {
      expect(screen.getByLabelText('Purpose')).toHaveValue('Changed elsewhere.')
    })
    expect(screen.getByLabelText('Name')).toHaveValue('Ambush')
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    await user.click(save())

    await waitFor(() => {
      expect(saved.sent).toHaveLength(2)
    })
    expect(saved.sent[1]?.expectedVersion).toBe(6)
  })

  it('warns that a reload replaces unsaved edits, and shows no saved time after it', async () => {
    let reads = 0
    let puts = 0
    const newer = { ...ambush, purpose: 'Changed elsewhere.' }
    const { user } = await openEditor('ambush', {
      [`GET ${base}/narrative`]: () =>
        Response.json(++reads === 1 ? narrativeScenes : withScene(newer, 7)),
      [put]: () =>
        ++puts === 1
          ? Response.json(withScene(ambush, 6))
          : Response.json(changed, { status: 409 }),
    })

    await replace(user, screen.getByLabelText('Name'), 'Mine')
    await user.click(save())
    expect(await screen.findByRole('status')).toHaveTextContent(/^Saved at/)
    await user.click(list('Closing').getByRole('button', { name: 'Add step' }))
    await user.click(await screen.findByRole('menuitem', { name: 'prompt' }))
    expect(list('Closing').getByRole('group', { name: 'Step 2' })).toBeInTheDocument()
    await user.click(save())
    await user.click(await screen.findByRole('button', { name: 'Reload the latest version' }))

    await waitFor(() => {
      expect(screen.getByLabelText('Purpose')).toHaveValue('Changed elsewhere.')
    })
    expect(list('Closing').queryByRole('group', { name: 'Step 2' })).not.toBeInTheDocument()
    expect(screen.queryByText(/Saved at/)).not.toBeInTheDocument()
  })

  it('says on the conflict that Reload replaces unsaved edits', async () => {
    const { user } = await openEditor('ambush', { [put]: json(changed, 409) })

    expect(screen.queryByText(/Reloading replaces/)).not.toBeInTheDocument()
    await replace(user, screen.getByLabelText('Name'), 'Mine')
    await user.click(save())

    expect(await screen.findByText(/Reloading replaces your unsaved edits/)).toHaveTextContent(
      'Reloading replaces your unsaved edits with the latest stored Scene Type.',
    )
  })

  it.each([
    [
      'is gone',
      (version: number) => ({ ...narrativeScenes, version, sceneTypes: [] }),
      /no longer exists/,
    ],
    [
      'has a shape it cannot read',
      (version: number) => withScene({ ...ambush, purpose: 42 }, version),
      /cannot read/,
    ],
  ])('keeps the form and the old version when the Scene Type %s', async (_, reloaded, note) => {
    let reads = 0
    const saved = recorder(() => Response.json(changed, { status: 409 }))
    const { user } = await openEditor('ambush', {
      [`GET ${base}/narrative`]: () => Response.json(++reads === 1 ? narrativeScenes : reloaded(6)),
      [put]: saved.handler,
    })

    await replace(user, screen.getByLabelText('Name'), 'Mine')
    await user.click(save())
    await user.click(await screen.findByRole('button', { name: 'Reload the latest version' }))

    expect(await screen.findByText(note)).toBeInTheDocument()
    expect(screen.getByLabelText('Name')).toHaveValue('Mine')
    await user.click(save())
    await waitFor(() => {
      expect(saved.sent).toHaveLength(2)
    })
    expect(saved.sent[1]?.expectedVersion).toBe(5)
  })

  it('says when the latest version could not be loaded, and keeps the form', async () => {
    let reads = 0
    const { user } = await openEditor('ambush', {
      [`GET ${base}/narrative`]: () =>
        ++reads === 1
          ? Response.json(narrativeScenes)
          : Response.json({ error: 'Gone' }, { status: 404 }),
      [put]: json(changed, 409),
    })

    await replace(user, screen.getByLabelText('Name'), 'Mine')
    await user.click(save())
    await user.click(await screen.findByRole('button', { name: 'Reload the latest version' }))

    expect(await screen.findByText(/Couldn.t reload the latest version/)).toBeInTheDocument()
    expect(screen.getByLabelText('Name')).toHaveValue('Mine')
  })
})

describe('opening from a cache that may be stale', () => {
  const draftRead = {
    [`GET ${base}`]: json(draft),
    [`GET ${base}/validation`]: json(validDraft),
  }
  const withoutPlay = withScene({ ...ambush, play: [] }, 5)

  /**
   * Opens Ambush, leaves it, and comes back to a stale cache: the editor mount reads it again, and
   * the form must wait for `settle`, which answers that read.
   */
  async function reopenWhileRefetching(answer: () => Response) {
    let reads = 0
    let release: () => void = () => undefined
    const gate = new Promise<void>((resolve) => {
      release = resolve
    })
    const saved = recorder(() => Response.json(withScene(ambush, 7)))
    const opened = await openEditor('ambush', {
      ...draftRead,
      [`GET ${base}/narrative`]: async () => {
        if (++reads === 1) {
          return Response.json(withoutPlay)
        }
        await gate
        return answer()
      },
      [put]: saved.handler,
    })
    expect(list('Play').queryByRole('group', { name: 'Step 1' })).not.toBeInTheDocument()
    await opened.user.click(screen.getByRole('link', { name: 'Back to my-draft' }))
    await waitFor(() => {
      expect(screen.queryByRole('form', { name: 'Scene Type' })).not.toBeInTheDocument()
    })
    // Stale, as it is once the stale time has passed; the next mount reads it again.
    await opened.queryClient.invalidateQueries({
      queryKey: narrativeKey('my-draft'),
      refetchType: 'none',
    })
    await act(() =>
      opened.router.navigate({
        to: '/studio/game-systems/$key/scene-types/$sceneType',
        params: { key: 'my-draft', sceneType: 'ambush' },
      }),
    )
    return { ...opened, saved, settle: release }
  }

  it('shows the steps the server holds, and saves with its version', async () => {
    const { user, saved, settle } = await reopenWhileRefetching(() =>
      Response.json(withScene(ambush, 6)),
    )

    expect(screen.getByText('Loading the Scene Type…')).toBeInTheDocument()
    settle()
    await waitFor(() => {
      expect(list('Play').getByRole('group', { name: 'Step 2' })).toBeInTheDocument()
    })
    await replace(user, screen.getByLabelText('Name'), 'Trap')
    await user.click(save())

    await waitFor(() => {
      expect(saved.sent).toHaveLength(1)
    })
    expect(saved.sent[0]?.expectedVersion).toBe(6)
    expect(saved.sent[0]?.item).toMatchObject({ play: ambush.play })
  })

  it('opens from the cache when the refetch fails, so a save still answers with a conflict', async () => {
    const { user, saved, settle } = await reopenWhileRefetching(() =>
      Response.json({ error: 'Gone' }, { status: 404 }),
    )

    expect(screen.getByText('Loading the Scene Type…')).toBeInTheDocument()
    settle()
    expect(await screen.findByRole('form', { name: 'Scene Type' })).toBeInTheDocument()
    expect(screen.queryByText('Loading the Scene Type…')).not.toBeInTheDocument()
    expect(list('Play').queryByRole('group', { name: 'Step 1' })).not.toBeInTheDocument()
    await user.click(save())
    await waitFor(() => {
      expect(saved.sent).toHaveLength(1)
    })
    expect(saved.sent[0]?.expectedVersion).toBe(5)
  })

  it('does not replace a form that is already open when a later read brings other data', async () => {
    let reads = 0
    const { user, queryClient } = await openEditor('ambush', {
      [`GET ${base}/narrative`]: () =>
        Response.json(
          ++reads === 1 ? narrativeScenes : withScene({ ...ambush, purpose: 'Elsewhere.' }, 6),
        ),
    })
    await replace(user, screen.getByLabelText('Name'), 'Mine')

    await act(() => queryClient.invalidateQueries({ queryKey: narrativeKey('my-draft') }))

    await waitFor(() => {
      expect(reads).toBe(2)
    })
    expect(screen.getByLabelText('Purpose')).toHaveValue('Something goes wrong on the street.')
    expect(screen.getByLabelText('Name')).toHaveValue('Mine')
  })
})

describe('a Scene Type that cannot be opened', () => {
  it('says so, with a way back, when the draft has no such Scene Type', async () => {
    renderAppAt('/studio/game-systems/my-draft/scene-types/nope', {
      'GET /api/auth/me': signedInAs('GAME_MANAGER'),
      [`GET ${base}/narrative`]: json(narrativeScenes),
    })

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'The draft has no Scene Type “nope”.',
    )
    expect(screen.getByRole('link', { name: 'Back to my-draft' })).toHaveAttribute(
      'href',
      '/studio/game-systems/my-draft',
    )
  })

  it('says so when the stored shape is one the editor cannot read, and offers no form', async () => {
    renderAppAt('/studio/game-systems/my-draft/scene-types/odd', {
      'GET /api/auth/me': signedInAs('GAME_MANAGER'),
      [`GET ${base}/narrative`]: json(narrativeScenes),
    })

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'has a shape this editor does not know',
    )
    expect(screen.queryByRole('form', { name: 'Scene Type' })).not.toBeInTheDocument()
  })

  it('offers the special results of the checks the draft holds, once they are loaded', async () => {
    const feed = { key: 'feed', kind: 'check', title: 'Feed', check: 'skill' }
    const scenes = {
      ...narrativeScenes,
      catalog: {
        ...narrativeScenes.catalog,
        checks: [{ key: 'skill', label: 'Skill', bands: ['fail', 'win'], difficulty: null }],
      },
      sceneTypes: [
        {
          key: 'ambush',
          definition: { ...ambush, play: [feed] },
          usedBy: [],
        },
        ...narrativeScenes.sceneTypes.slice(1),
      ],
    }
    const hunger = {
      key: 'skill',
      label: 'Skill',
      pool: { count: 3, sides: 10, successAt: 6, hunger: { count: { formula: '{hunger}' } } },
      bands: [
        { key: 'fail', label: 'Fail', upTo: 0 },
        { key: 'win', label: 'Win' },
      ],
    }
    await openEditor('ambush', {
      [`GET ${base}/narrative`]: json(scenes),
      [`GET ${base}/checks`]: json({
        version: 5,
        schemaVersion: 2,
        checks: [hunger],
        fields: [],
        trackers: [],
        conditions: [],
        usages: {},
      }),
    })

    expect(
      await card('Play', 1).findByRole('button', { name: 'Add outcome for Bestial failure' }),
    ).toBeInTheDocument()
    expect(card('Play', 1).queryByRole('button', { name: /Add outcome for Critical/ })).toBeNull()
  })

  it('says the GameSystem is not found when the narrative is a 404', async () => {
    renderAppAt('/studio/game-systems/my-draft/scene-types/ambush', {
      'GET /api/auth/me': signedInAs('GAME_MANAGER'),
      [`GET ${base}/narrative`]: json({ error: 'No GameSystem' }, 404),
    })

    expect(await screen.findByRole('alert')).toHaveTextContent('does not exist')
  })
})

describe('leaving with unsaved changes', () => {
  const dialog = () => screen.queryByRole('dialog', { name: 'Leave without saving?' })
  const back = () => screen.getByRole('link', { name: 'Back to my-draft' })
  // The GameSystem page the person leaves to.
  const draftRead = {
    [`GET ${base}`]: json(draft),
    [`GET ${base}/validation`]: json(validDraft),
  }

  it('asks first when the form is dirty, and stays on the page', async () => {
    const { user, router } = await openEditor('ambush', draftRead)

    await replace(user, screen.getByLabelText('Name'), 'Trap')
    await user.click(back())

    expect(await screen.findByRole('dialog', { name: 'Leave without saving?' })).toHaveTextContent(
      'Your changes have not been saved.',
    )
    expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft/scene-types/ambush')
  })

  it('keeps the draft on Stay and on Escape', async () => {
    const { user, router } = await openEditor('ambush', draftRead)

    await replace(user, screen.getByLabelText('Name'), 'Trap')
    await user.click(back())
    await user.click(await screen.findByRole('button', { name: 'Stay' }))
    await waitFor(() => {
      expect(dialog()).not.toBeInTheDocument()
    })
    expect(screen.getByLabelText('Name')).toHaveValue('Trap')

    await user.click(back())
    await screen.findByRole('dialog', { name: 'Leave without saving?' })
    await user.keyboard('{Escape}')
    await waitFor(() => {
      expect(dialog()).not.toBeInTheDocument()
    })
    expect(screen.getByLabelText('Name')).toHaveValue('Trap')
    expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft/scene-types/ambush')
  })

  it('leaves on Leave without saving', async () => {
    const { user, router } = await openEditor('ambush', draftRead)

    await replace(user, screen.getByLabelText('Name'), 'Trap')
    await user.click(back())
    await user.click(await screen.findByRole('button', { name: 'Leave without saving' }))

    await waitFor(() => {
      expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft')
    })
    expect(dialog()).not.toBeInTheDocument()
  })

  it('leaves a clean form without asking', async () => {
    const { user, router } = await openEditor('ambush', draftRead)

    await user.click(back())

    await waitFor(() => {
      expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft')
    })
    expect(dialog()).not.toBeInTheDocument()
  })

  it('leaves a just-saved form without asking', async () => {
    const saved = recorder(() => Response.json(withScene({ ...ambush, name: 'Trap' })))
    const { user, router } = await openEditor('ambush', { ...draftRead, [put]: saved.handler })

    await replace(user, screen.getByLabelText('Name'), 'Trap')
    await user.click(save())
    expect(await screen.findByRole('status')).toHaveTextContent(/^Saved at/)
    await user.click(back())

    await waitFor(() => {
      expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft')
    })
    expect(dialog()).not.toBeInTheDocument()
  })

  it('offers no dialog for a rename save, which opens the renamed Scene Type', async () => {
    const saved = recorder(() => Response.json(withScene({ ...ambush, key: 'trap' })))
    const { user, router } = await openEditor('ambush', { [put]: saved.handler })

    await replace(user, sceneKey(), 'trap')
    await user.click(save())

    await waitFor(() => {
      expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft/scene-types/trap')
    })
    expect(dialog()).not.toBeInTheDocument()
  })

  it('keeps what a pending save stores when the person leaves, and does not pull them back after a rename', async () => {
    let release: () => void = () => undefined
    const gate = new Promise<void>((resolve) => {
      release = resolve
    })
    const renamed = { ...ambush, key: 'trap' }
    const { user, router, queryClient } = await openEditor('ambush', {
      ...draftRead,
      [put]: async () => {
        await gate
        return Response.json(withScene(renamed, 9))
      },
    })

    await replace(user, sceneKey(), 'trap')
    await user.click(save())
    await user.click(back())
    await user.click(await screen.findByRole('button', { name: 'Leave without saving' }))
    await waitFor(() => {
      expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft')
    })
    // The editor has to be gone, not only the location changed, before the save is let through.
    await waitFor(() => {
      expect(screen.queryByRole('form', { name: 'Scene Type' })).not.toBeInTheDocument()
    })
    release()

    await waitFor(() => {
      expect(
        queryClient.getQueryData<typeof narrativeScenes>(narrativeKey('my-draft'))?.version,
      ).toBe(9)
    })
    expect(
      queryClient
        .getQueryData<typeof narrativeScenes>(narrativeKey('my-draft'))
        ?.sceneTypes.map(({ key }) => key),
    ).toContain('trap')
    expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft')
  })

  describe('while a save is pending', () => {
    /** Holds the save's answer until `release`, so the person can press Back meanwhile. */
    function held(respond: () => Response) {
      let release: () => void = () => undefined
      const gate = new Promise<void>((resolve) => {
        release = resolve
      })
      return {
        release: () => {
          release()
        },
        handler: async () => {
          await gate
          return respond()
        },
      }
    }

    it('closes the dialog and leaves when the save succeeds', async () => {
      const save$ = held(() => Response.json(withScene({ ...ambush, name: 'Trap' }, 9)))
      const { user, router, queryClient } = await openEditor('ambush', {
        ...draftRead,
        [put]: save$.handler,
      })

      await replace(user, screen.getByLabelText('Name'), 'Trap')
      await user.click(save())
      await user.click(back())
      await screen.findByRole('dialog', { name: 'Leave without saving?' })
      save$.release()

      await waitFor(() => {
        expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft')
      })
      expect(dialog()).not.toBeInTheDocument()
      expect(
        queryClient.getQueryData<typeof narrativeScenes>(narrativeKey('my-draft'))?.version,
      ).toBe(9)
    })

    it('keeps the dialog and the draft when the save fails, and shows the failure after Stay', async () => {
      const save$ = held(() => Response.json(changed, { status: 409 }))
      const { user, router } = await openEditor('ambush', { ...draftRead, [put]: save$.handler })

      await replace(user, screen.getByLabelText('Name'), 'Trap')
      await user.click(save())
      await user.click(back())
      await screen.findByRole('dialog', { name: 'Leave without saving?' })
      save$.release()
      await waitFor(() => {
        expect(screen.getAllByText(/was changed by another request/).length).toBeGreaterThan(0)
      })
      expect(dialog()).toBeInTheDocument()
      await user.click(screen.getByRole('button', { name: 'Stay' }))

      await waitFor(() => {
        expect(dialog()).not.toBeInTheDocument()
      })
      expect(screen.getByLabelText('Name')).toHaveValue('Trap')
      expect(await pageAlert()).toHaveTextContent('was changed by another request')
      expect(router.state.location.pathname).toBe(
        '/studio/game-systems/my-draft/scene-types/ambush',
      )
    })

    it('ends on one page with no dialog when a rename save succeeds', async () => {
      const save$ = held(() => Response.json(withScene({ ...ambush, key: 'trap' }, 9)))
      const { user, router } = await openEditor('ambush', { ...draftRead, [put]: save$.handler })

      await replace(user, sceneKey(), 'trap')
      await user.click(save())
      await user.click(back())
      await screen.findByRole('dialog', { name: 'Leave without saving?' })
      save$.release()

      await waitFor(() => {
        expect(dialog()).not.toBeInTheDocument()
      })
      // Pinned: the person ends where Back was taking them. The renamed editor is not reached.
      expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft')
      expect(router.state.status).toBe('idle')
    })
  })

  describe('leaving through the browser', () => {
    /**
     * An editor with a page behind it, on the browser's own history: a memory history never runs
     * the blockers on Back, only the browser's `popstate` does.
     */
    async function openAfterAnother(handlers: Record<string, FakeHandler> = {}) {
      window.history.replaceState(null, '', '/studio/game-systems/my-draft/scene-types/quiet')
      const { api } = createFakeApiClient({
        'GET /api/auth/me': signedInAs('GAME_MANAGER'),
        [`GET ${base}/narrative`]: json(narrativeScenes),
        ...handlers,
      })
      const queryClient = createQueryClient()
      const router = createAppRouter({ queryClient, api, history: createBrowserHistory() })
      render(
        <ColorModeProvider>
          <QueryClientProvider client={queryClient}>
            <ApiClientProvider client={api}>
              <RouterProvider router={router} />
            </ApiClientProvider>
          </QueryClientProvider>
        </ColorModeProvider>,
      )
      await screen.findByRole('form', { name: 'Scene Type' })
      await act(() =>
        router.navigate({
          to: '/studio/game-systems/$key/scene-types/$sceneType',
          params: { key: 'my-draft', sceneType: 'ambush' },
        }),
      )
      await waitFor(() => {
        expect(sceneKey()).toHaveValue('ambush')
      })
      return { user: userEvent.setup(), router }
    }

    it('asks on the browser Back button when the form is dirty, and stays', async () => {
      const { user, router } = await openAfterAnother()

      await replace(user, screen.getByLabelText('Name'), 'Trap')
      act(() => {
        window.history.back()
      })

      expect(
        await screen.findByRole('dialog', { name: 'Leave without saving?' }),
      ).toBeInTheDocument()
      expect(router.state.location.pathname).toBe(
        '/studio/game-systems/my-draft/scene-types/ambush',
      )
    })

    it('goes Back from a clean form without asking', async () => {
      const { router } = await openAfterAnother()

      act(() => {
        window.history.back()
      })

      await waitFor(() => {
        expect(router.state.location.pathname).toBe(
          '/studio/game-systems/my-draft/scene-types/quiet',
        )
      })
      expect(dialog()).not.toBeInTheDocument()
    })

    it('asks the browser to confirm closing the tab only while the form is dirty', async () => {
      const saved = recorder(() => Response.json(withScene({ ...ambush, name: 'Trap' })))
      const { user } = await openAfterAnother({ [put]: saved.handler })
      const closing = () => {
        const event = new Event('beforeunload', { cancelable: true })
        window.dispatchEvent(event)
        return event.defaultPrevented
      }

      expect(closing()).toBe(false)
      await replace(user, screen.getByLabelText('Name'), 'Trap')
      expect(closing()).toBe(true)
      await user.click(save())
      expect(await screen.findByRole('status')).toHaveTextContent(/^Saved at/)
      expect(closing()).toBe(false)
    })
  })

  it('drops an added step only after the person confirms leaving', async () => {
    const { user, router } = await openEditor('quiet', draftRead)

    await user.click(list('Closing').getByRole('button', { name: 'Add step' }))
    await user.click(await screen.findByRole('menuitem', { name: 'prompt' }))
    expect(list('Closing').getByRole('group', { name: 'Step 1' })).toBeInTheDocument()
    await user.click(back())
    await user.click(await screen.findByRole('button', { name: 'Stay' }))
    expect(list('Closing').getByRole('group', { name: 'Step 1' })).toBeInTheDocument()

    await user.click(back())
    await user.click(await screen.findByRole('button', { name: 'Leave without saving' }))
    await waitFor(() => {
      expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft')
    })
    await user.click(await screen.findByRole('link', { name: 'Scene Types' }))
    await user.click(await screen.findByRole('link', { name: 'Quiet' }))

    await screen.findByRole('form', { name: 'Scene Type' })
    expect(list('Closing').queryByRole('group', { name: 'Step 1' })).not.toBeInTheDocument()
  })
})
