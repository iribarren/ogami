import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { draft, legacy, validDraft } from './fixtures'

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

function renderPage(key: string, handlers: Record<string, FakeHandler>) {
  return renderAppAt(`/studio/game-systems/${key}`, {
    'GET /api/auth/me': signedInAs('GAME_MANAGER'),
    'GET /api/studio/game-systems': json([]),
    [`GET /api/studio/game-systems/${key}/validation`]: json(validDraft),
    ...handlers,
  })
}

function renderDraft(handlers: Record<string, FakeHandler> = {}) {
  return renderPage('my-draft', {
    'GET /api/studio/game-systems/my-draft': json(draft),
    ...handlers,
  })
}

async function metadataForm() {
  return screen.findByRole('form', { name: 'Metadata' })
}

describe('the GameSystem page', () => {
  it('shows the name, key, schema version and status of a never-published draft', async () => {
    renderDraft()

    expect(await screen.findByRole('heading', { level: 1, name: 'My draft' })).toBeInTheDocument()
    expect(screen.getByText('my-draft')).toBeInTheDocument()
    expect(screen.getByText('Schema version 2')).toBeInTheDocument()
    expect(screen.getByText('Never published')).toBeInTheDocument()
    expect(screen.getByText('Unpublished changes')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Back to Studio/ })).toHaveAttribute('href', '/studio')
  })

  it('shows the latest release of a published, unchanged GameSystem without the badge', async () => {
    renderPage('free-journal', { 'GET /api/studio/game-systems/free-journal': json(legacy) })

    expect(await screen.findByText('Latest release v2')).toBeInTheDocument()
    expect(screen.queryByText('Unpublished changes')).not.toBeInTheDocument()
  })

  it('shows a not-found state for an unknown or foreign key', async () => {
    renderPage('nobody', {
      'GET /api/studio/game-systems/nobody': json({ error: 'No such GameSystem.' }, 404),
    })

    expect(
      await screen.findByRole('heading', { level: 1, name: 'GameSystem not found' }),
    ).toBeInTheDocument()
  })

  it('shows why the GameSystem could not be loaded', async () => {
    renderDraft({
      'GET /api/studio/game-systems/my-draft': () => new Response('Oops', { status: 500 }),
    })

    expect(await screen.findByRole('alert', {}, { timeout: 3000 })).toHaveTextContent(
      'Loading the GameSystem failed with HTTP 500.',
    )
  })

  it('keeps the page and what was typed when a refetch fails, and refreshes on retry', async () => {
    const user = userEvent.setup()
    let failing = false
    renderDraft({
      'GET /api/studio/game-systems/my-draft': () =>
        failing ? new Response('Oops', { status: 500 }) : Response.json(draft),
      'PUT /api/studio/game-systems/my-draft/metadata': json({ error: 'Changed elsewhere.' }, 409),
    })

    const form = within(await metadataForm())
    await user.type(form.getByLabelText('Name'), '!')
    await user.click(form.getByRole('button', { name: 'Save' }))
    await form.findByRole('button', { name: 'Reload the latest version' })
    failing = true
    await user.click(form.getByRole('button', { name: 'Reload the latest version' }))

    expect(
      await screen.findByText(/Couldn't refresh this GameSystem/, {}, { timeout: 3000 }),
    ).toBeVisible()
    expect(screen.getByLabelText('Name')).toHaveValue('My draft!')
    expect(screen.getByRole('heading', { level: 1, name: 'My draft' })).toBeInTheDocument()

    failing = false
    await user.click(screen.getByRole('button', { name: 'Try again' }))
    await waitFor(() => {
      expect(screen.queryByText(/Couldn't refresh this GameSystem/)).not.toBeInTheDocument()
    })
    expect(screen.getByLabelText('Name')).toHaveValue('My draft!')
  })

  it('lists the section counts with their editors still to come', async () => {
    const user = userEvent.setup()
    renderDraft()

    await user.click(await screen.findByRole('radio', { name: 'Sections' }))

    const items = within(screen.getByRole('region', { name: 'Sections' })).getAllByRole('listitem')
    expect(items).toHaveLength(4)
    expect(items[0]).toHaveTextContent('Oracle tables')
    expect(items[0]).toHaveTextContent('2')
    expect(items[0]).toHaveTextContent('Editor coming')
  })
})

describe('the metadata form', () => {
  it('saves every field and shows the saved state', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    renderDraft({
      'PUT /api/studio/game-systems/my-draft/metadata': async (request) => {
        sent.push(await request.json())
        return Response.json({ ...draft, name: 'Renamed', theme: 'neon' })
      },
    })

    const form = within(await metadataForm())
    expect(form.getByLabelText('Theme')).toHaveDisplayValue('noir')
    await user.clear(form.getByLabelText('Name'))
    await user.type(form.getByLabelText('Name'), 'Renamed')
    await user.selectOptions(form.getByLabelText('Theme'), 'neon')
    await user.click(form.getByRole('button', { name: 'Save' }))

    expect(await form.findByRole('status')).toHaveTextContent('Saved.')
    expect(sent).toEqual([
      {
        name: 'Renamed',
        description: 'A game of my own.',
        credits: { notice: 'Unofficial fan content.', license: 'CC BY 4.0', url: null },
        theme: 'neon',
      },
    ])
    expect(await screen.findByRole('heading', { level: 1, name: 'Renamed' })).toBeInTheDocument()
  })

  it('hides the saved state again once the form is edited', async () => {
    const user = userEvent.setup()
    renderDraft({ 'PUT /api/studio/game-systems/my-draft/metadata': json(draft) })

    const form = within(await metadataForm())
    await user.click(form.getByRole('button', { name: 'Save' }))
    expect(await form.findByRole('status')).toHaveTextContent('Saved.')
    await user.type(form.getByLabelText('Name'), '!')

    expect(form.queryByRole('status')).not.toBeInTheDocument()
  })

  it('sends none for cleared optional fields', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    renderDraft({
      'PUT /api/studio/game-systems/my-draft/metadata': async (request) => {
        sent.push(await request.json())
        return Response.json(draft)
      },
    })

    const form = within(await metadataForm())
    await user.clear(form.getByLabelText('Description'))
    await user.clear(form.getByLabelText('Notice'))
    await user.clear(form.getByLabelText('License (optional)'))
    await user.selectOptions(form.getByLabelText('Theme'), 'None (the default app theme)')
    await user.click(form.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(sent).toEqual([{ name: 'My draft', description: null, credits: null, theme: null }])
    })
  })

  it('does not allow a license without a notice', async () => {
    const user = userEvent.setup()
    renderDraft()

    const form = within(await metadataForm())
    await user.clear(form.getByLabelText('Notice'))

    expect(form.getByText(/A license or link needs a notice/)).toBeInTheDocument()
    expect(form.getByRole('button', { name: 'Save' })).toBeDisabled()
  })

  it('explains a concurrent save and reloads the latest version on request', async () => {
    const user = userEvent.setup()
    let latest = draft
    renderDraft({
      'GET /api/studio/game-systems/my-draft': () => Response.json(latest),
      'PUT /api/studio/game-systems/my-draft/metadata': json(
        { error: 'The GameSystem was changed by someone else; reload and try again.' },
        409,
      ),
    })

    const form = within(await metadataForm())
    await user.type(form.getByLabelText('Name'), '!')
    await user.click(form.getByRole('button', { name: 'Save' }))
    expect(await form.findByRole('alert')).toHaveTextContent('reload and try again')

    latest = { ...draft, name: 'Changed elsewhere' }
    await user.click(form.getByRole('button', { name: 'Reload the latest version' }))

    await waitFor(() => {
      expect(screen.getByLabelText('Name')).toHaveValue('Changed elsewhere')
    })
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it.each([
    [422, 'credits.url: must be an https URL.'],
    [400, 'Send the metadata as JSON.'],
  ])('shows the API message of a %i refusal', async (status, message) => {
    const user = userEvent.setup()
    renderDraft({
      'PUT /api/studio/game-systems/my-draft/metadata': json({ error: message }, status),
    })

    const form = within(await metadataForm())
    await user.click(form.getByRole('button', { name: 'Save' }))

    expect(await form.findByRole('alert')).toHaveTextContent(message)
    expect(form.queryByRole('status')).not.toBeInTheDocument()
  })

  it('hides credits and theme on a schema version 1 GameSystem and does not send them', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    renderPage('free-journal', {
      'GET /api/studio/game-systems/free-journal': json(legacy),
      'PUT /api/studio/game-systems/free-journal/metadata': async (request) => {
        sent.push(await request.json())
        return Response.json(legacy)
      },
    })

    const form = within(await metadataForm())
    expect(form.queryByLabelText('Theme')).not.toBeInTheDocument()
    expect(form.queryByLabelText('Notice')).not.toBeInTheDocument()
    await user.click(form.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(sent).toEqual([{ name: 'Free journal', description: null }])
    })
  })
})

describe('the validation tab', () => {
  async function openValidation(validation: unknown) {
    const user = userEvent.setup()
    renderDraft({ 'GET /api/studio/game-systems/my-draft/validation': json(validation) })
    await user.click(await screen.findByRole('radio', { name: 'Validation' }))
  }

  it('says when the draft is valid', async () => {
    await openValidation(validDraft)

    expect(await screen.findByText(/The draft is valid/)).toBeInTheDocument()
    expect(screen.queryByText('Warnings')).not.toBeInTheDocument()
  })

  it('lists repeated warnings once each', async () => {
    await openValidation({
      valid: true,
      error: null,
      warnings: ['flows[1]: has no steps', 'flows[1]: has no steps'],
    })

    expect(await screen.findAllByRole('listitem')).toHaveLength(2)
  })

  it('shows the first error with its path', async () => {
    await openValidation({
      valid: false,
      error: { message: 'oracles: must be an object' },
      warnings: [],
    })

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'The first error: oracles: must be an object',
    )
  })

  it('lists the warnings of a valid draft', async () => {
    await openValidation({
      valid: true,
      error: null,
      warnings: ['oracles.tables[0]: has no entries', 'flows[1]: has no steps'],
    })

    const warnings = await screen.findAllByRole('listitem')
    expect(warnings).toHaveLength(2)
    expect(warnings[0]).toHaveTextContent('oracles.tables[0]: has no entries')
  })

  it('shows why the validation could not be loaded', async () => {
    const user = userEvent.setup()
    renderDraft({
      'GET /api/studio/game-systems/my-draft/validation': () =>
        new Response('Oops', { status: 500 }),
    })
    await user.click(await screen.findByRole('radio', { name: 'Validation' }))

    expect(await screen.findByRole('alert', {}, { timeout: 3000 })).toHaveTextContent(
      'Validating the GameSystem failed with HTTP 500.',
    )
  })
})

describe('discarding a draft', () => {
  it('is offered only while the GameSystem was never published', async () => {
    renderPage('free-journal', { 'GET /api/studio/game-systems/free-journal': json(legacy) })

    await screen.findByRole('form', { name: 'Metadata' })
    expect(screen.queryByRole('button', { name: 'Discard draft' })).not.toBeInTheDocument()
  })

  it('asks for confirmation, deletes the draft and goes back to Studio', async () => {
    const user = userEvent.setup()
    const { router, fakeApi } = renderDraft({
      'DELETE /api/studio/game-systems/my-draft': () => new Response(null, { status: 204 }),
    })

    await user.click(await screen.findByRole('button', { name: 'Discard draft' }))
    expect(fakeApi.requests).not.toContain('DELETE /api/studio/game-systems/my-draft')
    await user.click(await screen.findByRole('button', { name: 'Discard the draft' }))

    await waitFor(() => {
      expect(router.state.location.pathname).toBe('/studio')
    })
    expect(fakeApi.requests).toContain('DELETE /api/studio/game-systems/my-draft')
  })

  it('explains a refusal and stays on the page', async () => {
    const user = userEvent.setup()
    const { router } = renderDraft({
      'DELETE /api/studio/game-systems/my-draft': json(
        { error: 'The GameSystem has releases, which campaigns may play.' },
        409,
      ),
    })

    await user.click(await screen.findByRole('button', { name: 'Discard draft' }))
    await user.click(await screen.findByRole('button', { name: 'Discard the draft' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('has releases')
    expect(router.state.location.pathname).toBe('/studio/game-systems/my-draft')
  })
})
