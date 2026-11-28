import { screen, within } from '@testing-library/react'

import { miniRelease } from '@/shared/print/testRelease'
import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { lostMineCampaign, lostMineInNewSession } from '../campaigns/fixtures'
import type { Campaign } from '../campaigns/useCampaigns'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

const release = {
  gameSystemKey: 'free-journal',
  version: 2,
  publishedAt: '2026-10-01T12:00:00+00:00',
  content: miniRelease(),
  cardImages: [],
}

function renderTable(campaign: Campaign, handlers: Record<string, FakeHandler> = {}) {
  const path = `/api/campaigns/${campaign.id}`
  return renderAppAt(`/play/campaigns/${campaign.id}/table`, {
    'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    [`GET ${path}`]: json(campaign),
    [`GET ${path}/release`]: json(release),
    ...handlers,
  })
}

describe('TablePage', () => {
  it('lays the campaign on the mat, with the five slots and a way back to the Play page', async () => {
    renderTable(lostMineCampaign)

    expect(await screen.findByRole('heading', { name: 'The lost mine' })).toBeInTheDocument()
    const row = screen.getByRole('list', { name: 'This session' })
    expect(within(row).getByText('Into the dark')).toBeInTheDocument()
    expect(
      screen.getByRole('button', { name: 'Session 1, 1 scene, top card At the gate' }),
    ).toBeInTheDocument()
    for (const slot of ['Deck', 'Dice tray', 'Notebook', 'Oracle book', 'Flow map']) {
      expect(screen.getByRole('region', { name: slot })).toBeInTheDocument()
    }
    expect(screen.getByRole('link', { name: /Back to the Play page/ })).toHaveAttribute(
      'href',
      `/play/campaigns/${lostMineCampaign.id}`,
    )
  })

  it('shows a campaign between scenes with its earlier sessions as piles', async () => {
    renderTable(lostMineInNewSession)

    expect(await screen.findByText('No session is under way.')).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: /^Session \d/ })).toHaveLength(2)
  })

  it('says a campaign that is not found is not found', async () => {
    renderTable(lostMineCampaign, {
      [`GET /api/campaigns/${lostMineCampaign.id}`]: json({ error: 'No campaign.' }, 404),
    })

    expect(await screen.findByRole('heading', { name: 'Campaign not found' })).toBeInTheDocument()
  })

  it('says why the release could not be read instead of showing a table without it', async () => {
    renderTable(lostMineCampaign, {
      [`GET /api/campaigns/${lostMineCampaign.id}/release`]: json({ error: 'Broken.' }, 500),
    })

    expect((await screen.findByRole('alert')).textContent).toContain('500')
    expect(screen.queryByRole('list', { name: 'This session' })).not.toBeInTheDocument()
  })
})
