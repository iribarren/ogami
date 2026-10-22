import { act, fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { chooseOption } from '@/test/chooseOption'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { draft, draftWithLibraryChange, legacy, validDraft } from './fixtures'
import { packSystem } from './library/fixtures'

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

  it('warns of the library changes of the draft, and says nothing without any', async () => {
    renderDraft({ 'GET /api/studio/game-systems/my-draft': json(draftWithLibraryChange) })

    expect(await screen.findByText('1 library change')).toBeInTheDocument()
  })

  it('shows no library badge for a draft without library changes', async () => {
    renderDraft()

    await screen.findByRole('heading', { level: 1, name: 'My draft' })
    expect(screen.queryByText(/library change/)).not.toBeInTheDocument()
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

    await user.click(await screen.findByRole('link', { name: 'Sections' }))

    const items = within(screen.getByRole('region', { name: 'Sections' })).getAllByRole('listitem')
    expect(items).toHaveLength(4)
    expect(items[0]).toHaveTextContent('Oracle tables')
    expect(items[0]).toHaveTextContent('2')
    expect(items[0]).toHaveTextContent('Editor coming')
  })
})

const linkNames = (scope: HTMLElement) =>
  within(scope)
    .getAllByRole('link')
    .map((link) => link.textContent)

describe('the section rail', () => {
  it('groups the sections into Author and Ship and marks the open one', async () => {
    renderDraft()

    const rail = await screen.findByRole('navigation', { name: 'GameSystem page' })
    expect(linkNames(within(rail).getByRole('group', { name: 'Author' }))).toEqual([
      'Overview',
      'Oracles2',
      'Trackers & tags1',
      'Scene Types',
      'Flows0',
      'Sheet',
      'Checks3',
      'Library',
    ])
    expect(linkNames(within(rail).getByRole('group', { name: 'Ship' }))).toEqual([
      'Validation',
      'Releases',
      'Sections',
    ])
    expect(within(rail).getByRole('link', { name: 'Overview' })).toHaveAttribute(
      'aria-current',
      'page',
    )
    expect(within(rail).getByRole('link', { name: 'Oracles' })).not.toHaveAttribute('aria-current')
  })

  it('links every section with a tab search parameter and describes it by its count', async () => {
    renderDraft()

    const oracles = await screen.findByRole('link', { name: 'Oracles' })
    expect(oracles).toHaveAttribute('href', '/studio/game-systems/my-draft?tab=oracles')
    expect(oracles).toHaveAccessibleDescription('2')
    expect(screen.getByRole('link', { name: 'Checks' })).toHaveAccessibleDescription('3')
  })

  it('opens a section from its link and marks it as the current page', async () => {
    const user = userEvent.setup()
    const { router } = renderDraft()

    await user.click(await screen.findByRole('link', { name: 'Sections' }))

    expect(router.state.location.search).toEqual({ tab: 'sections' })
    expect(await screen.findByRole('region', { name: 'Sections' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Sections' })).toHaveAttribute('aria-current', 'page')
    expect(screen.getByRole('link', { name: 'Overview' })).not.toHaveAttribute('aria-current')
  })

  it('opens the section named by the URL', async () => {
    renderAppAt('/studio/game-systems/my-draft?tab=sections', {
      'GET /api/auth/me': signedInAs('GAME_MANAGER'),
      'GET /api/studio/game-systems/my-draft': json(draft),
    })

    expect(await screen.findByRole('region', { name: 'Sections' })).toBeInTheDocument()
  })

  it.each(['nonsense', '', 'sections,sheet'])('falls back to Overview for tab=%j', async (tab) => {
    renderAppAt(`/studio/game-systems/my-draft?tab=${tab}`, {
      'GET /api/auth/me': signedInAs('GAME_MANAGER'),
      'GET /api/studio/game-systems/my-draft': json(draft),
    })

    expect(await screen.findByRole('link', { name: 'Overview' })).toHaveAttribute(
      'aria-current',
      'page',
    )
    expect(await metadataForm()).toBeVisible()
  })

  it('shows a library pack only the sections it has', async () => {
    renderPage('fantasy-basics', {
      'GET /api/studio/game-systems/fantasy-basics': json(packSystem),
    })

    const rail = await screen.findByRole('navigation', { name: 'GameSystem page' })
    expect(linkNames(rail)).toEqual([
      'Overview',
      'Oracles2',
      'Trackers & tags1',
      'Scene Types',
      'Validation',
    ])
  })

  it('shows the publish notice in the header and clears it when another section opens', async () => {
    const user = userEvent.setup()
    renderDraft({
      'POST /api/studio/game-systems/my-draft/releases': () =>
        Response.json({ version: 1 }, { status: 201 }),
    })

    await user.click(await screen.findByRole('button', { name: 'Publish' }))
    const dialog = within(await screen.findByRole('dialog'))
    await dialog.findByText('This creates version 1.')
    await user.click(dialog.getByRole('button', { name: 'Publish version 1' }))

    const notice = await screen.findByRole('status')
    expect(notice).toHaveTextContent('Published version 1.')
    expect(notice.closest('header')).toContainElement(
      screen.getByRole('heading', { level: 1, name: 'My draft' }),
    )

    await user.click(screen.getByRole('link', { name: 'Sections' }))
    expect(screen.queryByText('Published version 1.')).not.toBeInTheDocument()
  })

  it('does not bring the publish notice back when history returns to its section', async () => {
    const user = userEvent.setup()
    const { router } = renderDraft({
      'POST /api/studio/game-systems/my-draft/releases': () =>
        Response.json({ version: 1 }, { status: 201 }),
    })

    await user.click(await screen.findByRole('button', { name: 'Publish' }))
    const dialog = within(await screen.findByRole('dialog'))
    await dialog.findByText('This creates version 1.')
    await user.click(dialog.getByRole('button', { name: 'Publish version 1' }))
    await screen.findByText('Published version 1.')

    // Leaving by the address bar or the back button is not a rail click.
    await act(() =>
      router.navigate({
        to: '/studio/game-systems/$key',
        params: { key: 'my-draft' },
        search: { tab: 'sections' },
      }),
    )
    expect(screen.queryByText('Published version 1.')).not.toBeInTheDocument()
    await act(async () => {
      router.history.back()
      await Promise.resolve()
    })
    await waitFor(() => {
      expect(screen.getByRole('link', { name: 'Overview' })).toHaveAttribute('aria-current', 'page')
    })
    expect(screen.queryByText('Published version 1.')).not.toBeInTheDocument()
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
  })
})

describe('the metadata form', () => {
  it('counts the notice against its limit, linked to the field', async () => {
    const user = userEvent.setup()
    renderDraft()

    const notice = within(await metadataForm()).getByLabelText('Notice')
    expect(notice).toHaveAccessibleDescription('23 / 2,000')
    await user.type(notice, '!!')
    expect(notice).toHaveAccessibleDescription('25 / 2,000')
  })

  it('counts code points like the server and blocks Save over the limit', async () => {
    const user = userEvent.setup()
    renderDraft()

    const form = within(await metadataForm())
    const notice = form.getByLabelText('Notice')
    // Each emoji is one character for the server (two UTF-16 units): 2,000 of them fit.
    fireEvent.change(notice, { target: { value: '😀'.repeat(2000) } })
    expect(notice).toHaveAccessibleDescription('2,000 / 2,000')
    expect(notice).not.toBeInvalid()
    expect(form.getByRole('button', { name: 'Save' })).toBeEnabled()

    fireEvent.change(notice, { target: { value: '😀'.repeat(2001) } })
    expect(notice).toHaveAccessibleDescription('2,001 / 2,000')
    expect(notice).toBeInvalid()
    expect(form.getByText('The notice is limited to 2,000 characters.')).toBeInTheDocument()
    expect(form.getByRole('button', { name: 'Save' })).toBeDisabled()

    await user.clear(notice)
    await user.type(notice, 'Short again.')
    expect(notice).not.toBeInvalid()
    expect(form.getByRole('button', { name: 'Save' })).toBeEnabled()
  })

  it('shows Saving while the save is pending, then the time of the save', async () => {
    const user = userEvent.setup()
    let finish: (response: Response) => void = () => undefined
    renderDraft({
      'PUT /api/studio/game-systems/my-draft/metadata': () =>
        new Promise<Response>((resolve) => {
          finish = resolve
        }),
    })

    const form = within(await metadataForm())
    expect(form.queryByText(/Saving|Saved at|Not saved/)).not.toBeInTheDocument()
    await user.click(form.getByRole('button', { name: 'Save' }))
    expect(await form.findByText('Saving…')).toBeInTheDocument()

    finish(Response.json(draft))
    expect(await form.findByRole('status')).toHaveTextContent(/^Saved at \d/)
    expect(form.queryByText('Saving…')).not.toBeInTheDocument()
  })

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
    expect(form.getByLabelText('Theme')).toHaveTextContent('noir')
    await user.clear(form.getByLabelText('Name'))
    await user.type(form.getByLabelText('Name'), 'Renamed')
    await chooseOption(user, 'Theme', 'neon', form)
    await user.click(form.getByRole('button', { name: 'Save' }))

    expect(await form.findByRole('status')).toHaveTextContent(/^Saved at \d/)
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
    expect(await form.findByRole('status')).toHaveTextContent(/^Saved at \d/)
    await user.type(form.getByLabelText('Name'), '!')

    expect(form.queryByRole('status')).not.toBeInTheDocument()
  })

  async function savedThenEdited(edit: 'description' | 'theme') {
    const user = userEvent.setup()
    renderDraft({ 'PUT /api/studio/game-systems/my-draft/metadata': json(draft) })

    const form = within(await metadataForm())
    await user.click(form.getByRole('button', { name: 'Save' }))
    expect(await form.findByRole('status')).toHaveTextContent(/^Saved at \d/)
    if (edit === 'description') {
      await user.type(form.getByLabelText('Description'), '!')
    } else {
      await chooseOption(user, 'Theme', 'neon', form)
    }
    return form
  }

  it('hides the saved state once the description is edited', async () => {
    const form = await savedThenEdited('description')

    expect(form.queryByRole('status')).not.toBeInTheDocument()
  })

  it('hides the saved state once the theme is changed', async () => {
    const form = await savedThenEdited('theme')

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
    await chooseOption(user, 'Theme', 'None (the default app theme)', form)
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
    expect(await form.findByRole('alert')).toHaveTextContent('Not saved: 1 error')
    expect(form.getByText(/reload and try again/)).toBeInTheDocument()

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

    expect(await form.findByRole('alert')).toHaveTextContent('Not saved: 1 error')
    expect(form.getByText(message)).toBeInTheDocument()
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

describe('switching tabs', () => {
  it('keeps what was typed in the form', async () => {
    const user = userEvent.setup()
    renderDraft()

    await user.type(within(await metadataForm()).getByLabelText('Name'), '!')
    await user.click(screen.getByRole('link', { name: 'Validation' }))
    expect(screen.queryByRole('form', { name: 'Metadata' })).not.toBeInTheDocument()
    await user.click(screen.getByRole('link', { name: 'Overview' }))

    expect(within(await metadataForm()).getByLabelText('Name')).toHaveValue('My draft!')
  })

  it('shows the saved values, not the ones before the save', async () => {
    const user = userEvent.setup()
    renderDraft({
      'PUT /api/studio/game-systems/my-draft/metadata': json({ ...draft, name: 'Renamed' }),
    })

    const form = within(await metadataForm())
    await user.clear(form.getByLabelText('Name'))
    await user.type(form.getByLabelText('Name'), 'Renamed')
    await user.click(form.getByRole('button', { name: 'Save' }))
    await form.findByRole('status')
    await user.click(screen.getByRole('link', { name: 'Sections' }))
    await user.click(screen.getByRole('link', { name: 'Overview' }))

    expect(within(await metadataForm()).getByLabelText('Name')).toHaveValue('Renamed')
  })
})

describe('the validation tab', () => {
  async function openValidation(validation: unknown) {
    const user = userEvent.setup()
    renderDraft({ 'GET /api/studio/game-systems/my-draft/validation': json(validation) })
    await user.click(await screen.findByRole('link', { name: 'Validation' }))
  }

  it('says when the draft is valid', async () => {
    await openValidation(validDraft)

    expect(await screen.findByText(/The draft is valid/)).toBeInTheDocument()
    expect(screen.queryByText('Warnings')).not.toBeInTheDocument()
  })

  it('lists repeated warnings once each', async () => {
    await openValidation({
      valid: true,
      errors: [],
      warnings: ['flows[1]: has no steps', 'flows[1]: has no steps'],
    })

    expect(await screen.findAllByRole('listitem')).toHaveLength(2)
  })

  it('shows the first error with its path', async () => {
    await openValidation({
      valid: false,
      errors: [{ path: 'oracles', message: 'must be an object' }],
      warnings: [],
    })

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'The first error: oracles: must be an object',
    )
  })

  it('lists the warnings of a valid draft', async () => {
    await openValidation({
      valid: true,
      errors: [],
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
    await user.click(await screen.findByRole('link', { name: 'Validation' }))

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

describe('a library pack', () => {
  function renderPack(handlers: Record<string, FakeHandler> = {}) {
    return renderPage('fantasy-basics', {
      'GET /api/studio/game-systems/fantasy-basics': json(packSystem),
      ...handlers,
    })
  }

  it('is marked as a pack, with no release status, library badge or publish button', async () => {
    renderPack()

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Fantasy basics' }),
    ).toBeInTheDocument()
    expect(screen.getByText('Library pack')).toBeInTheDocument()
    expect(screen.queryByText('Never published')).not.toBeInTheDocument()
    expect(screen.queryByText('Unpublished changes')).not.toBeInTheDocument()
    expect(screen.queryByText(/library change/)).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Publish/ })).not.toBeInTheDocument()
  })

  it('edits only the name and description, and saves them without credits or theme', async () => {
    const user = userEvent.setup()
    const sent: unknown[] = []
    renderPack({
      'PUT /api/studio/game-systems/fantasy-basics/metadata': async (request) => {
        sent.push(await request.json())
        return Response.json({ ...packSystem, name: 'Fantasy basics 2' })
      },
    })

    const form = within(await metadataForm())
    expect(form.queryByLabelText('Theme')).not.toBeInTheDocument()
    expect(form.queryByLabelText('Notice')).not.toBeInTheDocument()
    await user.type(form.getByLabelText('Name'), ' 2')
    await user.click(form.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(sent).toEqual([
        { name: 'Fantasy basics 2', description: 'Names, rumours and a tavern.' },
      ])
    })
    expect(await form.findByText(/^Saved at \d/)).toBeInTheDocument()
  })

  it('validates the pack without talking of a release', async () => {
    const user = userEvent.setup()
    renderPack()

    await user.click(await screen.findByRole('link', { name: 'Validation' }))

    expect(await screen.findByText("The pack's items are valid.")).toBeInTheDocument()
    expect(screen.queryByText(/published as a release/)).not.toBeInTheDocument()
  })

  it('can be discarded, and says pack', async () => {
    const user = userEvent.setup()
    const { router, fakeApi } = renderPack({
      'DELETE /api/studio/game-systems/fantasy-basics': () => new Response(null, { status: 204 }),
    })

    await user.click(await screen.findByRole('button', { name: 'Discard pack' }))
    await user.click(await screen.findByRole('button', { name: 'Discard the pack' }))

    await waitFor(() => {
      expect(router.state.location.pathname).toBe('/studio')
    })
    expect(fakeApi.requests).toContain('DELETE /api/studio/game-systems/fantasy-basics')
  })
})
