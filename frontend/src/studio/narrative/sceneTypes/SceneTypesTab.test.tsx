import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { draft, narrativeScenes, narrativeV1, validDraft } from '../../fixtures'

const base = '/api/studio/game-systems/my-draft'

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

async function openTab(handlers: Record<string, FakeHandler> = {}) {
  const user = userEvent.setup()
  const rendered = renderAppAt('/studio/game-systems/my-draft', {
    'GET /api/auth/me': signedInAs('GAME_MANAGER'),
    'GET /api/studio/game-systems': json([]),
    [`GET ${base}`]: json(draft),
    [`GET ${base}/validation`]: json(validDraft),
    [`GET ${base}/narrative`]: json(narrativeScenes),
    ...handlers,
  })
  await user.click(await screen.findByRole('radio', { name: 'Scene Types' }))
  return { user, ...rendered }
}

const inDialog = () => within(screen.getByRole('dialog'))
const row = async (name: string) => (await screen.findByText(name)).closest('li') as HTMLElement

const changed = {
  error: 'GameSystem "my-draft" was changed by another request. Reload it and try again.',
}

describe('the Scene Types tab', () => {
  it('lists the Scene Types in stored order with step counts, where each is used and a link to its editor', async () => {
    await openTab()

    const ambushRow = await row('Ambush')
    const quiet = await row('Quiet')
    expect(ambushRow).toHaveTextContent('ambush')
    expect(ambushRow).toHaveTextContent('setup 1 · play 2 · closing 1')
    expect(ambushRow).toHaveTextContent('Used by flows[0].phases[0].selection.sceneTypes[0]')
    expect(within(ambushRow).getByRole('link', { name: 'Ambush' })).toHaveAttribute(
      'href',
      '/studio/game-systems/my-draft/scene-types/ambush',
    )
    expect(quiet).toHaveTextContent('setup 0 · play 0 · closing 0')
    expect(quiet).toHaveTextContent('Not used')
    const names = within(screen.getByRole('region', { name: 'Scene Types' }))
      .getAllByRole('listitem')
      .map((item) => item.querySelector('span.font-mono')?.textContent)
    expect(names).toEqual(['ambush', 'quiet', 'odd'])
  })

  it('lists a Scene Type of a shape it cannot read read-only, with a reason, and still lets it go', async () => {
    await openTab()

    const odd = await row('Odd')
    expect(odd).toHaveTextContent("Can't be edited here")
    expect(within(odd).queryByRole('link')).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Odd' })).not.toBeInTheDocument()
    expect(within(odd).getByText('odd')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Delete odd' })).toBeEnabled()
  })

  it('has an empty state', async () => {
    await openTab({ [`GET ${base}/narrative`]: json({ ...narrativeScenes, sceneTypes: [] }) })

    expect(await screen.findByText('This draft has no Scene Types yet.')).toBeInTheDocument()
  })

  it('says a schema version 1 draft has no narrative sections and offers no create button', async () => {
    await openTab({ [`GET ${base}/narrative`]: json(narrativeV1) })

    expect(await screen.findByText(/have no narrative sections/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'New Scene Type' })).not.toBeInTheDocument()
  })

  it('shows why the narrative could not be loaded', async () => {
    await openTab({ [`GET ${base}/narrative`]: json({ error: 'No GameSystem' }, 404) })

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'This GameSystem does not exist, or it is not one of yours.',
    )
  })
})

describe('creating a Scene Type', () => {
  it('sends the smallest Scene Type the publisher accepts and opens its editor', async () => {
    const sent: unknown[] = []
    const created = {
      key: 'heist',
      name: 'Heist',
      purpose: 'Steal it.',
      oracles: [],
      setup: [],
      play: [],
      closing: [],
    }
    const { user, router } = await openTab({
      [`POST ${base}/narrative/scene-types`]: async (request) => {
        sent.push(await request.json())
        return Response.json(
          {
            ...narrativeScenes,
            version: 6,
            sceneTypes: [
              ...narrativeScenes.sceneTypes,
              { key: 'heist', definition: created, usedBy: [] },
            ],
          },
          { status: 201 },
        )
      },
    })

    await user.click(await screen.findByRole('button', { name: 'New Scene Type' }))
    await user.type(inDialog().getByLabelText('Key'), ' heist ')
    await user.type(inDialog().getByLabelText('Name'), 'Heist')
    await user.type(inDialog().getByLabelText('Purpose'), 'Steal it.')
    await user.click(inDialog().getByRole('button', { name: 'Create Scene Type' }))

    expect(await screen.findByRole('form', { name: 'Scene Type' })).toBeInTheDocument()
    expect(screen.getByLabelText('Key')).toHaveValue('heist')
    expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft/scene-types/heist')
    expect(sent).toEqual([{ expectedVersion: 5, item: created }])
  })

  it('says the key is taken in the dialog and stays there', async () => {
    const { user } = await openTab({
      [`POST ${base}/narrative/scene-types`]: json(
        { error: 'The draft already has a Scene Type "ambush".' },
        409,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'New Scene Type' }))
    await user.type(inDialog().getByLabelText('Key'), 'ambush')
    await user.type(inDialog().getByLabelText('Name'), 'Again')
    await user.type(inDialog().getByLabelText('Purpose'), 'Twice.')
    await user.click(inDialog().getByRole('button', { name: 'Create Scene Type' }))

    expect(await inDialog().findByRole('alert')).toHaveTextContent(
      'The draft already has a Scene Type "ambush".',
    )
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('shows what the publisher refuses, with the path', async () => {
    const { user } = await openTab({
      [`POST ${base}/narrative/scene-types`]: json(
        { error: 'sceneTypes[3].purpose: must not be blank' },
        422,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'New Scene Type' }))
    await user.type(inDialog().getByLabelText('Key'), 'heist')
    await user.type(inDialog().getByLabelText('Name'), 'Heist')
    await user.click(inDialog().getByRole('button', { name: 'Create Scene Type' }))

    expect(await inDialog().findByRole('alert')).toHaveTextContent(
      'sceneTypes[3].purpose: must not be blank',
    )
  })
})

describe('deleting a Scene Type', () => {
  it('disables deleting one the draft uses, and says why', async () => {
    await openTab()

    const button = await screen.findByRole('button', { name: 'Delete ambush' })
    expect(button).toBeDisabled()
    expect(button).toHaveAccessibleDescription('Used by flows[0].phases[0].selection.sceneTypes[0]')
    expect(screen.getByRole('button', { name: 'Delete quiet' })).toBeEnabled()
  })

  it('deletes an unused one with the version it read', async () => {
    let deleted: URL | undefined
    const { user } = await openTab({
      [`DELETE ${base}/narrative/scene-types/quiet`]: (request) => {
        deleted = new URL(request.url)
        return Response.json({
          ...narrativeScenes,
          version: 6,
          sceneTypes: narrativeScenes.sceneTypes.filter(({ key }) => key !== 'quiet'),
        })
      },
    })

    await user.click(await screen.findByRole('button', { name: 'Delete quiet' }))
    await user.click(screen.getByRole('button', { name: 'Delete the Scene Type' }))

    await waitFor(() => {
      expect(screen.queryByText('Quiet')).not.toBeInTheDocument()
    })
    expect(deleted?.pathname).toBe(`${base}/narrative/scene-types/quiet`)
    expect(deleted?.searchParams.get('expectedVersion')).toBe('5')
  })

  it.each([
    [
      404,
      { error: 'The draft has no Scene Type "quiet".' },
      'The draft has no Scene Type "quiet".',
    ],
    [
      422,
      { error: 'The Scene Type "quiet" is used.', paths: ['flows[0].phases[1].sceneOpening[2]'] },
      'The Scene Type "quiet" is used. Used by flows[0].phases[1].sceneOpening[2].',
    ],
  ])('shows the %s answer in the dialog', async (status, body, message) => {
    const { user } = await openTab({
      [`DELETE ${base}/narrative/scene-types/quiet`]: json(body, status),
    })

    await user.click(await screen.findByRole('button', { name: 'Delete quiet' }))
    await user.click(screen.getByRole('button', { name: 'Delete the Scene Type' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(message)
  })

  it('offers to reload when the draft changed since it was read', async () => {
    let reads = 0
    const { user } = await openTab({
      [`GET ${base}/narrative`]: () =>
        Response.json(
          ++reads === 1
            ? narrativeScenes
            : {
                ...narrativeScenes,
                version: 6,
                sceneTypes: narrativeScenes.sceneTypes.slice(0, 1),
              },
        ),
      [`DELETE ${base}/narrative/scene-types/quiet`]: json(changed, 409),
    })

    await user.click(await screen.findByRole('button', { name: 'Delete quiet' }))
    await user.click(screen.getByRole('button', { name: 'Delete the Scene Type' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('was changed by another request')
    await user.click(screen.getByRole('button', { name: 'Reload the latest version' }))

    await waitFor(() => {
      expect(screen.queryByText('Quiet')).not.toBeInTheDocument()
    })
  })
})
