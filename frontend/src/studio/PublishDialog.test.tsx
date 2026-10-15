import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { draft, published3, validDraft } from './fixtures'
import { conflictMessage } from './PublishDialog'

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

const release = {
  version: 1,
  schemaVersion: 2,
  publishedAt: '2026-10-16T10:00:00+00:00',
  notes: null,
  contentHash: 'a'.repeat(64),
}

function renderPublishable(gameSystem = draft, handlers: Record<string, FakeHandler> = {}) {
  const base = `/api/studio/game-systems/${gameSystem.key}`
  return renderAppAt(`/studio/game-systems/${gameSystem.key}`, {
    'GET /api/auth/me': signedInAs('GAME_MANAGER'),
    'GET /api/studio/game-systems': json([]),
    [`GET ${base}`]: json(gameSystem),
    [`GET ${base}/validation`]: json(validDraft),
    ...handlers,
  })
}

async function openDialog(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole('button', { name: 'Publish' }))
  return within(await screen.findByRole('dialog'))
}

describe('the publish dialog', () => {
  it('shows the warnings of a valid draft and the version a publish creates', async () => {
    const user = userEvent.setup()
    renderPublishable(published3, {
      'GET /api/studio/game-systems/noir-detective/validation': json({
        valid: true,
        error: null,
        warnings: ['flows[1]: has no steps', 'flows[1]: has no steps'],
      }),
    })

    const dialog = await openDialog(user)

    expect(await dialog.findByText('This creates version 4.')).toBeInTheDocument()
    expect(dialog.getAllByRole('listitem')).toHaveLength(2)
    expect(dialog.getByRole('button', { name: 'Publish version 4' })).toBeEnabled()
    expect(dialog.getByText('0 / 2,000 characters')).toBeInTheDocument()
  })

  it('creates version 1 for a draft that was never published', async () => {
    const user = userEvent.setup()
    renderPublishable()

    const dialog = await openDialog(user)

    expect(await dialog.findByText('This creates version 1.')).toBeInTheDocument()
  })

  it('shows the first error of an invalid draft and cannot confirm', async () => {
    const user = userEvent.setup()
    renderPublishable(draft, {
      'GET /api/studio/game-systems/my-draft/validation': json({
        valid: false,
        error: { message: 'oracles: must be an object' },
        warnings: [],
      }),
    })

    const dialog = await openDialog(user)

    expect(await dialog.findByRole('alert')).toHaveTextContent('oracles: must be an object')
    expect(dialog.getByRole('button', { name: 'Publish version 1' })).toBeDisabled()
  })

  it('cannot confirm when the validation could not be loaded', async () => {
    const user = userEvent.setup()
    renderPublishable(draft, {
      'GET /api/studio/game-systems/my-draft/validation': () =>
        new Response('Oops', { status: 500 }),
    })

    const dialog = await openDialog(user)

    expect(await dialog.findByRole('alert', {}, { timeout: 3000 })).toHaveTextContent(
      'Validating the GameSystem failed with HTTP 500.',
    )
    expect(dialog.getByRole('button', { name: 'Publish version 1' })).toBeDisabled()
  })

  it('sends the notes exactly as typed', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    renderPublishable(draft, {
      'POST /api/studio/game-systems/my-draft/releases': async (request) => {
        sent.push(await request.json())
        return Response.json(release, { status: 201 })
      },
    })

    const dialog = await openDialog(user)
    await dialog.findByText('This creates version 1.')
    await user.type(dialog.getByLabelText('Release notes (optional)'), '  Rebalanced the oracles. ')
    expect(dialog.getByText('26 / 2,000 characters')).toBeInTheDocument()
    await user.click(dialog.getByRole('button', { name: 'Publish version 1' }))

    await waitFor(() => {
      expect(sent).toEqual([{ notes: '  Rebalanced the oracles. ' }])
    })
  })

  it('omits empty notes, closes, refreshes and says what was published', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    const { fakeApi } = renderPublishable(draft, {
      'POST /api/studio/game-systems/my-draft/releases': async (request) => {
        sent.push(await request.json())
        return Response.json(release, { status: 201 })
      },
    })

    const dialog = await openDialog(user)
    await dialog.findByText('This creates version 1.')
    await user.type(dialog.getByLabelText('Release notes (optional)'), '   ')
    const gets = () => fakeApi.requests.filter((r) => r === 'GET /api/studio/game-systems/my-draft')
    const before = gets().length
    await user.click(dialog.getByRole('button', { name: 'Publish version 1' }))

    expect(await screen.findByText('Published version 1.')).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(sent).toEqual([{}])
    await waitFor(() => {
      expect(gets().length).toBeGreaterThan(before)
    })
  })

  it.each([
    [422, { error: 'notes: must not be blank.' }, 'notes: must not be blank.'],
    [409, { error: 'No changes: "my-draft" is the same as its release v1.' }, conflictMessage],
    [409, { error: 'Another release of my-draft v1 was published.' }, conflictMessage],
    [400, { error: 'Send the notes as a string.' }, 'Send the notes as a string.'],
  ])('explains a %i refusal inside the dialog', async (status, body, message) => {
    const user = userEvent.setup()
    renderPublishable(draft, {
      'POST /api/studio/game-systems/my-draft/releases': json(body, status),
    })

    const dialog = await openDialog(user)
    await dialog.findByText('This creates version 1.')
    await user.click(dialog.getByRole('button', { name: 'Publish version 1' }))

    expect(await dialog.findByRole('alert')).toHaveTextContent(message)
    expect(screen.queryByText(/^Published version/)).not.toBeInTheDocument()
  })

  it('shows a generic message for any other failure', async () => {
    const user = userEvent.setup()
    renderPublishable(draft, {
      'POST /api/studio/game-systems/my-draft/releases': () =>
        new Response('Oops', { status: 500 }),
    })

    const dialog = await openDialog(user)
    await dialog.findByText('This creates version 1.')
    await user.click(dialog.getByRole('button', { name: 'Publish version 1' }))

    expect(await dialog.findByRole('alert')).toHaveTextContent(
      'Publishing the GameSystem failed with HTTP 500.',
    )
  })
})
