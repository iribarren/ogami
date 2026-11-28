import { screen } from '@testing-library/react'

import { flowDiagramFromReleaseContent } from '@/shared/flowDiagram'
import { buildCards } from '@/shared/print/cards'
import { miniRelease } from '@/shared/print/testRelease'
import { renderWithApi } from '@/test/renderWithApi'

import { useCampaignRelease } from './useCampaignRelease'

const hash = 'a'.repeat(64)
const path = '/api/campaigns/campaign-1'

const answer = (cardImages: object[] = []) =>
  Response.json({
    gameSystemKey: 'mini',
    version: 3,
    publishedAt: '2026-11-28T10:00:00+00:00',
    content: miniRelease(),
    cardImages,
  })

const pin = (slot: string, url: string) => ({
  slot,
  sha256: hash,
  mediaType: 'image/png',
  width: 815,
  height: 1110,
  url,
})

/** Feeds the hook's result, untouched, to the two readers the table is built on. */
function Probe() {
  const { data, error } = useCampaignRelease('campaign-1')
  if (error !== null) {
    return <p role="alert">{error.message}</p>
  }
  if (data === undefined) {
    return null
  }
  const cards = buildCards(data.release, { flow: 'mini' })
  const diagram = flowDiagramFromReleaseContent(data.content, 'mini')
  return (
    <p data-testid="probe">
      {[
        data.gameSystemKey,
        data.version,
        cards.sceneCards.length,
        diagram.phases.length,
        data.images.cardBack ?? 'no back',
        data.images.cardFront ?? 'no front',
      ].join('|')}
    </p>
  )
}

describe('useCampaignRelease', () => {
  it('reads the pinned release into what buildCards and the flow diagram take unchanged', async () => {
    renderWithApi(<Probe />, { [`GET ${path}/release`]: () => answer() })

    expect((await screen.findByTestId('probe')).textContent).toBe('mini|3|1|2|no back|no front')
  })

  it('gives the image addresses the API returned, by slot', async () => {
    const back = `${path}/card-images/${hash}`
    renderWithApi(<Probe />, {
      [`GET ${path}/release`]: () => answer([pin('cardBack', back)]),
    })

    expect((await screen.findByTestId('probe')).textContent).toBe(`mini|3|1|2|${back}|no front`)
  })

  it('refuses an image address that is not a campaign image read path', async () => {
    renderWithApi(<Probe />, {
      [`GET ${path}/release`]: () => answer([pin('cardBack', 'https://elsewhere.test/back.png')]),
    })

    expect((await screen.findByRole('alert')).textContent).toContain('cardBack')
  })

  it('refuses a slot the table does not show', async () => {
    renderWithApi(<Probe />, {
      [`GET ${path}/release`]: () => answer([pin('cardSide', `${path}/card-images/${hash}`)]),
    })

    expect((await screen.findByRole('alert')).textContent).toContain('slot')
  })

  it('says why a campaign that is not found has no release', async () => {
    renderWithApi(<Probe />, {
      [`GET ${path}/release`]: () => Response.json({ error: 'No campaign.' }, { status: 404 }),
    })

    expect((await screen.findByRole('alert')).textContent).toBe('No campaign.')
  })
})
