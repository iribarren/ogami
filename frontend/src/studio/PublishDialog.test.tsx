import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { draft, published3, validDraft } from './fixtures'
import { StoryProviders } from '@/test/StoryProviders'

import { conflictMessage, PublishErrors } from './PublishDialog'
import { focusNotes } from './publishFocus'

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
        errors: [],
        warnings: [
          'flows[1]: has no steps',
          'flows[1]: has no steps',
          'no location at all',
          'Heads up: this is only text',
        ],
      }),
    })

    const dialog = await openDialog(user)

    expect(await dialog.findByText('This creates version 4.')).toBeInTheDocument()
    expect(dialog.getAllByRole('listitem')).toHaveLength(4)
    // The reason and the place in words, not the raw path.
    expect(dialog.getAllByText('has no steps')).toHaveLength(2)
    expect(dialog.getAllByText('Flows · 2')).toHaveLength(2)
    expect(dialog.queryByText(/flows\[1\]/)).not.toBeInTheDocument()
    expect(dialog.getByText('no location at all')).toBeInTheDocument()
    // A colon alone is not a path: the whole warning stays, with no location.
    expect(dialog.getByText('Heads up: this is only text')).toBeInTheDocument()
    expect(dialog.getByRole('button', { name: 'Publish version 4' })).toBeEnabled()
    expect(dialog.getByText('0 / 2,000')).toBeInTheDocument()
  })

  it('opens with the release notes focused, not the Close button', async () => {
    const user = userEvent.setup()
    renderPublishable()

    const dialog = await openDialog(user)

    expect(dialog.getByRole('textbox', { name: /Release notes/ })).toHaveFocus()
    expect(dialog.queryByRole('tooltip')).not.toBeInTheDocument()
  })

  it('keeps focus inside the dialog when the draft is invalid', async () => {
    const user = userEvent.setup()
    renderPublishable(draft, {
      'GET /api/studio/game-systems/my-draft/validation': json({
        valid: false,
        errors: [{ path: 'oracles', message: 'must be an object' }],
        warnings: [],
      }),
    })

    await openDialog(user)

    expect(screen.getByRole('dialog')).toContainElement(document.activeElement as HTMLElement)
  })

  it('creates version 1 for a draft that was never published', async () => {
    const user = userEvent.setup()
    renderPublishable()

    const dialog = await openDialog(user)

    expect(await dialog.findByText('This creates version 1.')).toBeInTheDocument()
  })

  it('shows the error of an invalid draft and cannot confirm', async () => {
    const user = userEvent.setup()
    renderPublishable(draft, {
      'GET /api/studio/game-systems/my-draft/validation': json({
        valid: false,
        errors: [{ path: 'oracles', message: 'must be an object' }],
        warnings: [],
      }),
    })

    const dialog = await openDialog(user)

    // The reason and the place in words, not the raw path.
    const alert = await dialog.findByRole('alert')
    expect(alert).toHaveTextContent('must be an object')
    expect(alert).toHaveTextContent('Oracles')
    expect(alert).not.toHaveTextContent('oracles: must')
    expect(dialog.getByRole('button', { name: 'Publish version 1' })).toBeDisabled()
  })

  it('lists the first three errors, then how many more', async () => {
    const user = userEvent.setup()
    renderPublishable(draft, {
      'GET /api/studio/game-systems/my-draft/validation': json({
        valid: false,
        errors: ['a', 'b', 'c', 'd', 'e'].map((name) => ({
          path: `trackers[0].${name}`,
          message: 'is wrong',
        })),
        warnings: [],
      }),
    })

    const dialog = await openDialog(user)

    const alert = await dialog.findByRole('alert')
    expect(within(alert).getAllByRole('listitem')).toHaveLength(3)
    // Each error says where it is in words (the narrative names are read for it).
    expect(alert).toHaveTextContent('is wrong')
    expect(alert).toHaveTextContent('Trackers · 1 › C')
    expect(alert).not.toHaveTextContent('Trackers · 1 › D')
    expect(alert).not.toHaveTextContent('trackers[0]')
    expect(alert).toHaveTextContent('and 2 more')
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
    expect(dialog.getByText('26 / 2,000')).toBeInTheDocument()
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

  it('refreshes the next version after a race 409 and reloads on request', async () => {
    const user = userEvent.setup()
    let latest = published3
    const { fakeApi } = renderPublishable(published3, {
      'GET /api/studio/game-systems/noir-detective': () => Response.json(latest),
      'POST /api/studio/game-systems/noir-detective/releases': json(
        { error: 'Another release of noir-detective v4 was published.' },
        409,
      ),
    })

    const dialog = await openDialog(user)
    await dialog.findByText('This creates version 4.')
    latest = { ...published3, latestReleaseVersion: 4 }
    await user.click(dialog.getByRole('button', { name: 'Publish version 4' }))

    expect(await dialog.findByRole('alert')).toHaveTextContent(conflictMessage)
    expect(await dialog.findByRole('button', { name: 'Publish version 5' })).toBeEnabled()
    const before = fakeApi.requests.filter(
      (r) => r === 'GET /api/studio/game-systems/noir-detective',
    ).length
    await user.click(dialog.getByRole('button', { name: 'Reload' }))

    await waitFor(() => {
      expect(dialog.queryByRole('alert')).not.toBeInTheDocument()
    })
    expect(
      fakeApi.requests.filter((r) => r === 'GET /api/studio/game-systems/noir-detective').length,
    ).toBeGreaterThan(before)
    expect(dialog.getByText('This creates version 5.')).toBeInTheDocument()
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

  it('shows the move from the latest release to the next one, and the draft as valid', async () => {
    const user = userEvent.setup()
    renderPublishable(published3, {
      'GET /api/studio/game-systems/noir-detective/validation': json(validDraft),
    })

    const dialog = await openDialog(user)

    expect(await dialog.findByText('The draft is valid.')).toBeInTheDocument()
    expect(dialog.getByText(/^v3/)).toHaveTextContent('v3 → to v4')
    expect(dialog.getByRole('button', { name: 'Close' })).toBeInTheDocument()
    await user.click(dialog.getByRole('button', { name: 'Cancel' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('counts characters like the server and refuses notes over the limit', async () => {
    const user = userEvent.setup()
    renderPublishable()

    const dialog = await openDialog(user)
    await dialog.findByText('This creates version 1.')
    const notes = dialog.getByLabelText('Release notes (optional)')
    // Two code points each; the server counts those, not UTF-16 units.
    await user.click(notes)
    await user.paste('😀'.repeat(2000))
    expect(dialog.getByText('2,000 / 2,000')).toBeInTheDocument()
    expect(notes).toHaveAttribute('aria-invalid', 'false')
    expect(dialog.getByRole('button', { name: 'Publish version 1' })).toBeEnabled()

    await user.type(notes, 'x')

    expect(dialog.getByText('2,001 / 2,000')).toBeInTheDocument()
    expect(notes).toHaveAttribute('aria-invalid', 'true')
    expect(notes).toHaveAccessibleDescription('2,001 / 2,000')
    expect(dialog.getByText(/limited to 2,000 characters/)).toBeInTheDocument()
    expect(dialog.getByRole('button', { name: 'Publish version 1' })).toBeDisabled()
  })
})

describe('the errors of a publish', () => {
  it('lists the first three and links to the Validation tab for the rest', async () => {
    render(
      <StoryProviders handlers={{}}>
        <PublishErrors
          errors={['a: one', 'b: two', 'c: three', 'd: four', 'e: five']}
          gameSystemKey="my-draft"
        />
      </StoryProviders>,
    )

    expect((await screen.findAllByRole('listitem')).map((item) => item.textContent)).toEqual([
      'a: one',
      'b: two',
      'c: three',
    ])
    expect(screen.getByText(/and 2 more/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'see the Validation tab' })).toHaveAttribute(
      'href',
      '/studio/game-systems/my-draft?tab=validation',
    )
  })

  it('lists one error without a link', async () => {
    render(
      <StoryProviders handlers={{}}>
        <PublishErrors errors={['a: one']} gameSystemKey="my-draft" />
      </StoryProviders>,
    )

    expect(await screen.findAllByRole('listitem')).toHaveLength(1)
    expect(screen.queryByRole('link')).not.toBeInTheDocument()
  })
})

describe('focusNotes', () => {
  function openEvent(container: HTMLElement) {
    const event = new Event('focus', { cancelable: true })
    Object.defineProperty(event, 'currentTarget', { value: container })
    return event
  }

  it('focuses the notes field and keeps Radix from focusing the Close button', () => {
    const container = document.createElement('div')
    container.innerHTML = '<button>Close</button><textarea></textarea>'
    document.body.append(container)
    const event = openEvent(container)

    focusNotes(event)

    expect(event.defaultPrevented).toBe(true)
    expect(document.activeElement).toBe(container.querySelector('textarea'))
    container.remove()
  })

  it('leaves the default focus alone without a notes field', () => {
    const container = document.createElement('div')
    container.innerHTML = '<button>Close</button>'
    const event = openEvent(container)

    focusNotes(event)

    expect(event.defaultPrevented).toBe(false)
  })
})
