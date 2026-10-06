import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { freeJournal, lostMine, mythicStyle, newCampaign, sunkenTemple } from './fixtures'

const soloPlayer = signedInAs('SOLO_PLAYER')

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

function campaignList() {
  return screen.getByRole('region', { name: 'Your campaigns' })
}

function newCampaignForm() {
  return screen.getByRole('form', { name: 'New campaign' })
}

describe('the Play campaign list', () => {
  it('lists my campaigns with their GameSystem release and creation date, each linking to it', async () => {
    renderAppAt('/play', {
      'GET /api/auth/me': soloPlayer,
      'GET /api/campaigns': json([lostMine, sunkenTemple]),
      'GET /api/play/game-systems': json([freeJournal, mythicStyle]),
    })

    expect(await screen.findByRole('heading', { level: 1, name: 'Play' })).toBeInTheDocument()
    const items = await within(campaignList()).findAllByRole('listitem')
    expect(items).toHaveLength(2)
    expect(items[0]).toHaveTextContent('The lost mine')
    expect(items[0]).toHaveTextContent('Free journal v2')
    expect(items[0]).toHaveTextContent('Created 5 Oct 2026')
    expect(items[1]).toHaveTextContent('Mythic style v1')
    expect(within(campaignList()).getByRole('link', { name: 'The lost mine' })).toHaveAttribute(
      'href',
      `/play/campaigns/${lostMine.id}`,
    )
  })

  it('says when I have no campaign yet', async () => {
    renderAppAt('/play', {
      'GET /api/auth/me': soloPlayer,
      'GET /api/campaigns': json([]),
      'GET /api/play/game-systems': json([freeJournal]),
    })

    expect(
      await within(await screen.findByRole('region', { name: 'Your campaigns' })).findByText(
        'You have no campaigns yet. Create one below to start playing.',
      ),
    ).toBeInTheDocument()
  })

  it('shows why the campaigns could not be loaded', async () => {
    renderAppAt('/play', {
      'GET /api/auth/me': soloPlayer,
      'GET /api/campaigns': () => new Response('Oops', { status: 500 }),
      'GET /api/play/game-systems': json([freeJournal]),
    })

    expect(
      await within(await screen.findByRole('region', { name: 'Your campaigns' })).findByRole(
        'alert',
        {},
        { timeout: 3000 },
      ),
    ).toHaveTextContent('Loading your campaigns failed with HTTP 500.')
  })

  it('creates a campaign from the chosen GameSystem and opens it', async () => {
    const sent: unknown[] = []
    const created = newCampaign('The haunted keep', mythicStyle)
    let campaigns = [lostMine]
    const { router, fakeApi } = renderAppAt('/play', {
      'GET /api/auth/me': soloPlayer,
      'GET /api/campaigns': () => Response.json(campaigns),
      'GET /api/play/game-systems': json([freeJournal, mythicStyle]),
      'POST /api/campaigns': async (request) => {
        sent.push(await request.json())
        campaigns = [{ ...lostMine, id: created.id, name: created.name }, ...campaigns]
        return Response.json(created, { status: 201 })
      },
      [`GET /api/campaigns/${created.id}`]: json(created),
    })
    const user = userEvent.setup()

    const form = await screen.findByRole('form', { name: 'New campaign' })
    await within(form).findByRole('option', { name: 'Mythic style v1' })
    await user.type(within(form).getByLabelText('Campaign name'), '  The haunted keep ')
    await user.selectOptions(within(form).getByLabelText('GameSystem'), 'Mythic style v1')
    await user.click(within(form).getByRole('button', { name: 'Create campaign' }))

    expect(
      await screen.findByRole('heading', { level: 1, name: 'The haunted keep' }),
    ).toBeInTheDocument()
    expect(sent).toEqual([{ name: '  The haunted keep ', gameSystemKey: 'mythic-style' }])
    expect(router.state.location.pathname).toBe(`/play/campaigns/${created.id}`)
    // The list is refetched, so going back shows the new campaign.
    await waitFor(() => {
      expect(fakeApi.requests.filter((r) => r === 'GET /api/campaigns')).toHaveLength(2)
    })
  })

  it('starts on the first GameSystem and keeps the button disabled until a name is typed', async () => {
    renderAppAt('/play', {
      'GET /api/auth/me': soloPlayer,
      'GET /api/campaigns': json([]),
      'GET /api/play/game-systems': json([freeJournal, mythicStyle]),
    })
    const user = userEvent.setup()

    const form = await screen.findByRole('form', { name: 'New campaign' })
    await within(form).findByRole('option', { name: 'Free journal v2' })
    expect(within(form).getByLabelText('GameSystem')).toHaveDisplayValue('Free journal v2')
    const create = within(form).getByRole('button', { name: 'Create campaign' })
    expect(create).toBeDisabled()
    await user.type(within(form).getByLabelText('Campaign name'), '   ')
    expect(create).toBeDisabled()
    await user.type(within(form).getByLabelText('Campaign name'), 'A')
    expect(create).toBeEnabled()
    expect(within(form).getByLabelText('Campaign name')).toHaveAttribute('maxLength', '100')
  })

  it('disables the button while the campaign is being created', async () => {
    let answer: (response: Response) => void = () => undefined
    renderAppAt('/play', {
      'GET /api/auth/me': soloPlayer,
      'GET /api/campaigns': json([]),
      'GET /api/play/game-systems': json([freeJournal]),
      'POST /api/campaigns': () =>
        new Promise<Response>((resolve) => {
          answer = resolve
        }),
    })
    const user = userEvent.setup()

    const form = await screen.findByRole('form', { name: 'New campaign' })
    await within(form).findByRole('option', { name: 'Free journal v2' })
    await user.type(within(form).getByLabelText('Campaign name'), 'The lost mine')
    await user.click(within(form).getByRole('button', { name: 'Create campaign' }))

    expect(within(form).getByRole('button', { name: 'Create campaign' })).toBeDisabled()
    answer(Response.json({ error: 'Busy.' }, { status: 409 }))
    expect(await within(form).findByRole('alert')).toHaveTextContent('Busy.')
    expect(within(form).getByRole('button', { name: 'Create campaign' })).toBeEnabled()
  })

  it('shows the API message when the campaign is refused, and stays on the list', async () => {
    const { router } = renderAppAt('/play', {
      'GET /api/auth/me': soloPlayer,
      'GET /api/campaigns': json([]),
      'GET /api/play/game-systems': json([freeJournal]),
      'POST /api/campaigns': json(
        { error: 'A campaign name must be at most 100 characters, got 101.' },
        422,
      ),
    })
    const user = userEvent.setup()

    const form = await screen.findByRole('form', { name: 'New campaign' })
    await within(form).findByRole('option', { name: 'Free journal v2' })
    await user.type(within(form).getByLabelText('Campaign name'), 'The lost mine')
    await user.click(within(form).getByRole('button', { name: 'Create campaign' }))

    expect(await within(newCampaignForm()).findByRole('alert')).toHaveTextContent(
      'A campaign name must be at most 100 characters, got 101.',
    )
    expect(router.state.location.pathname).toBe('/play')
  })

  it('shows a generic message when creating fails unexpectedly', async () => {
    renderAppAt('/play', {
      'GET /api/auth/me': soloPlayer,
      'GET /api/campaigns': json([]),
      'GET /api/play/game-systems': json([freeJournal]),
      'POST /api/campaigns': () => new Response('Oops', { status: 500 }),
    })
    const user = userEvent.setup()

    const form = await screen.findByRole('form', { name: 'New campaign' })
    await within(form).findByRole('option', { name: 'Free journal v2' })
    await user.type(within(form).getByLabelText('Campaign name'), 'The lost mine')
    await user.click(within(form).getByRole('button', { name: 'Create campaign' }))

    expect(await within(form).findByRole('alert')).toHaveTextContent(
      'Creating the campaign failed with HTTP 500.',
    )
  })

  it('explains that no GameSystem is published when the catalog is empty', async () => {
    renderAppAt('/play', {
      'GET /api/auth/me': soloPlayer,
      'GET /api/campaigns': json([]),
      'GET /api/play/game-systems': json([]),
    })

    const section = await screen.findByRole('region', { name: 'New campaign' })
    expect(
      await within(section).findByText(
        'No GameSystem is published yet, so there is nothing to start a campaign from.',
      ),
    ).toBeInTheDocument()
    expect(screen.queryByRole('form', { name: 'New campaign' })).not.toBeInTheDocument()
  })
})
