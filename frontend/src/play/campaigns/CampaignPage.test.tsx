import { screen } from '@testing-library/react'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { freeJournal, lostMine, lostMineCampaign, newCampaign } from './fixtures'
import type { Campaign } from './useCampaigns'

const soloPlayer = signedInAs('SOLO_PLAYER')

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

describe('the campaign page', () => {
  const created: Campaign = newCampaign('The haunted keep', freeJournal)

  it('shows the campaign name, its pinned release and where play stands', async () => {
    renderAppAt(`/play/campaigns/${lostMine.id}`, {
      'GET /api/auth/me': soloPlayer,
      [`GET /api/campaigns/${lostMine.id}`]: json(lostMineCampaign),
    })

    expect(
      await screen.findByRole('heading', { level: 1, name: 'The lost mine' }),
    ).toBeInTheDocument()
    expect(screen.getByText('Free journal v2')).toBeInTheDocument()
    const status = screen.getByRole('region', { name: 'Where play stands' })
    expect(status).toHaveTextContent('Sessions2')
    expect(status).toHaveTextContent('Current sessionSession 2')
    expect(status).toHaveTextContent('Current sceneScene 1: Into the dark')
    expect(screen.getByRole('link', { name: 'Back to your campaigns' })).toHaveAttribute(
      'href',
      '/play',
    )
  })

  it('says when no session has started yet', async () => {
    renderAppAt(`/play/campaigns/${created.id}`, {
      'GET /api/auth/me': soloPlayer,
      [`GET /api/campaigns/${created.id}`]: json(created),
    })

    const status = await screen.findByRole('region', { name: 'Where play stands' })
    expect(status).toHaveTextContent('Sessions0')
    expect(status).toHaveTextContent('Current sessionNone yet')
    expect(status).toHaveTextContent('Current sceneNone yet')
  })

  it('shows a not found state for an unknown or foreign campaign, without retrying', async () => {
    const { fakeApi } = renderAppAt(`/play/campaigns/${created.id}`, {
      'GET /api/auth/me': soloPlayer,
      [`GET /api/campaigns/${created.id}`]: json({ error: 'Campaign not found.' }, 404),
    })

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Campaign not found' }),
    ).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Back to your campaigns' })).toBeInTheDocument()
    expect(fakeApi.requests.filter((r) => r.startsWith('GET /api/campaigns/'))).toHaveLength(1)
  })

  it('keeps the solo player guard', async () => {
    renderAppAt(`/play/campaigns/${created.id}`, { 'GET /api/auth/me': signedInAs('OWNER') })

    expect(
      await screen.findByRole('heading', { level: 1, name: 'Access denied' }),
    ).toBeInTheDocument()
    expect(screen.getByText("You don't have access to Play.")).toBeInTheDocument()
  })

  it('sends anonymous visitors to the sign-in page and back', async () => {
    const { router } = renderAppAt(`/play/campaigns/${created.id}`)

    expect(await screen.findByRole('heading', { level: 1, name: 'Sign in' })).toBeInTheDocument()
    expect(router.state.location.search).toEqual({ redirect: `/play/campaigns/${created.id}` })
  })
})
