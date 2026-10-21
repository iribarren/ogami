import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { published3, releaseHistory, releaseTwo, validDraft } from './fixtures'

const base = '/api/studio/game-systems/noir-detective'

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

async function openReleases(handlers: Record<string, FakeHandler> = {}) {
  const user = userEvent.setup()
  const rendered = renderAppAt('/studio/game-systems/noir-detective', {
    'GET /api/auth/me': signedInAs('GAME_MANAGER'),
    'GET /api/studio/game-systems': json([]),
    [`GET ${base}`]: json(published3),
    [`GET ${base}/validation`]: json(validDraft),
    [`GET ${base}/releases`]: json(releaseHistory),
    [`GET ${base}/releases/2`]: json(releaseTwo),
    ...handlers,
  })
  await user.click(await screen.findByRole('link', { name: 'Releases' }))
  return { user, ...rendered }
}

describe('the releases tab', () => {
  it('lists the releases newest first with their details', async () => {
    await openReleases()

    const items = within(await screen.findByRole('region', { name: 'Releases' })).getAllByRole(
      'listitem',
    )
    expect(items).toHaveLength(2)
    expect(items[0]).toHaveTextContent('Version 3')
    expect(items[0]).toHaveTextContent('Schema version 2')
    // Shown in local time like the app's other dates, so the expected text uses the same zone.
    const published = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short' })
    expect(items[0]).toHaveTextContent(published.format(new Date('2026-10-14T12:00:00+00:00')))
    expect(items[0]).toHaveTextContent('No notes')
    expect(items[0]).toHaveTextContent('11d93fc7')
    expect(items[0]).not.toHaveTextContent('11d93fc7a')
    expect(items[1]).toHaveTextContent('Version 2')
    expect(items[1]).toHaveTextContent('Rebalanced the oracles.')
  })

  it('shows the library sources of a release by pack, and nothing when it has none', async () => {
    await openReleases()

    const items = within(await screen.findByRole('region', { name: 'Releases' })).getAllByRole(
      'listitem',
    )
    expect(items[0]).toHaveTextContent(
      'Library: fantasy-basics (npc-name, social); wild-tales (noble)',
    )
    expect(items[1]).not.toHaveTextContent('Library:')
  })

  it('shows no library line for an empty list of sources, and the section of a key two sections share', async () => {
    const [latest, older] = releaseHistory
    await openReleases({
      [`GET ${base}/releases`]: json([
        {
          ...latest,
          librarySources: [
            { section: 'tags', key: 'social', pack: 'fantasy-basics' },
            { section: 'scene-types', key: 'social', pack: 'fantasy-basics' },
            { section: 'tags', key: 'social', pack: 'wild-tales' },
          ],
        },
        { ...older, librarySources: [] },
      ]),
    })

    const items = within(await screen.findByRole('region', { name: 'Releases' })).getAllByRole(
      'listitem',
    )
    expect(items[0]).toHaveTextContent(
      'Library: fantasy-basics (social (tags), social (scene-types)); wild-tales (social)',
    )
    expect(items[1]).not.toHaveTextContent('Library:')
  })

  it('has an empty state for a GameSystem that was never published', async () => {
    await openReleases({ [`GET ${base}/releases`]: json([]) })

    expect(await screen.findByText('Never published', { selector: 'p' })).toBeInTheDocument()
    expect(screen.queryByRole('list')).not.toBeInTheDocument()
  })

  it('shows why the history could not be loaded', async () => {
    await openReleases({ [`GET ${base}/releases`]: () => new Response('Oops', { status: 500 }) })

    expect(await screen.findByRole('alert', {}, { timeout: 3000 })).toHaveTextContent(
      'Loading the releases failed with HTTP 500.',
    )
  })

  it('shows a release read-only, with its name and section counts', async () => {
    const { user } = await openReleases()

    await user.click(await screen.findByRole('button', { name: 'View version 2' }))

    const view = within(await screen.findByRole('region', { name: 'Version 2' }))
    expect(view.getByRole('heading')).toHaveTextContent('Version 2: Noir detective (old name)')
    expect(view.getByText('Oracle tables')).toBeInTheDocument()
    expect(view.queryByText('Editor coming')).not.toBeInTheDocument()
    expect(view.queryByRole('textbox')).not.toBeInTheDocument()
  })

  it('shows why a release could not be loaded', async () => {
    const { user } = await openReleases({
      [`GET ${base}/releases/2`]: json({ error: 'No such release.' }, 404),
    })

    await user.click(await screen.findByRole('button', { name: 'View version 2' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('No such release.')
  })
})

describe('restoring a release into the draft', () => {
  async function openRestore(handlers: Record<string, FakeHandler> = {}) {
    const rendered = await openReleases(handlers)
    await rendered.user.click(await screen.findByRole('button', { name: 'View version 2' }))
    await rendered.user.click(await screen.findByRole('button', { name: 'Restore into the draft' }))
    return { ...rendered, dialog: within(await screen.findByRole('dialog')) }
  }

  it('asks for confirmation, warning that the draft and unsaved edits are replaced', async () => {
    const { dialog, fakeApi, user } = await openRestore()

    expect(dialog.getByText(/replaced by version 2/)).toBeInTheDocument()
    expect(dialog.getByText(/you have not saved are lost/)).toBeInTheDocument()
    await user.keyboard('{Escape}')

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(fakeApi.requests).not.toContain(`POST ${base}/restore`)
  })

  it('sends the version and shows the restored content in the Overview form', async () => {
    const sent: unknown[] = []
    const restored = { ...published3, name: 'Noir detective (old name)', description: 'Restored.' }
    const { dialog, user } = await openRestore({
      [`POST ${base}/restore`]: async (request) => {
        sent.push(await request.json())
        return Response.json(restored)
      },
    })

    await user.click(dialog.getByRole('button', { name: 'Restore version 2' }))

    expect(await screen.findByText('Restored version 2 into the draft.')).toBeInTheDocument()
    expect(sent).toEqual([{ version: 2 }])
    await waitFor(() => {
      expect(screen.getByLabelText('Name')).toHaveValue('Noir detective (old name)')
    })
    expect(screen.getByLabelText('Description')).toHaveValue('Restored.')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('offers a reload after a concurrent save (409)', async () => {
    const { dialog, user, fakeApi } = await openRestore({
      [`POST ${base}/restore`]: json({ error: 'The GameSystem was changed; reload.' }, 409),
    })

    await user.click(dialog.getByRole('button', { name: 'Restore version 2' }))
    expect(await dialog.findByRole('alert')).toHaveTextContent('was changed; reload.')
    const before = fakeApi.requests.filter((r) => r === `GET ${base}`).length
    await user.click(dialog.getByRole('button', { name: 'Reload the latest version' }))

    await waitFor(() => {
      expect(fakeApi.requests.filter((r) => r === `GET ${base}`).length).toBeGreaterThan(before)
    })
    expect(screen.queryByText(/Restored version/)).not.toBeInTheDocument()
  })

  it.each([
    [422, { error: 'version: this GameSystem has no release 2.' }, 'has no release 2.'],
    [400, { error: 'Send a version.' }, 'Send a version.'],
    [404, { error: 'No such GameSystem.' }, 'No such GameSystem.'],
  ])('explains a %i refusal and changes nothing', async (status, body, message) => {
    const { dialog, user } = await openRestore({ [`POST ${base}/restore`]: json(body, status) })

    await user.click(dialog.getByRole('button', { name: 'Restore version 2' }))

    expect(await dialog.findByRole('alert')).toHaveTextContent(message)
    expect(screen.queryByText(/Restored version/)).not.toBeInTheDocument()
  })

  it('shows a generic message for any other failure', async () => {
    const { dialog, user } = await openRestore({
      [`POST ${base}/restore`]: () => new Response('Oops', { status: 500 }),
    })

    await user.click(dialog.getByRole('button', { name: 'Restore version 2' }))

    expect(await dialog.findByRole('alert')).toHaveTextContent(
      'Restoring the release failed with HTTP 500.',
    )
  })
})
