import { screen } from '@testing-library/react'

import { miniRelease } from '@/shared/print/testRelease'
import { renderAppAt, signedInAs } from '@/test/renderApp'

const CONTENT = 'GET /api/studio/game-systems/mini/releases/3/content'
const handlers = {
  'GET /api/auth/me': signedInAs('GAME_MANAGER'),
  [CONTENT]: () =>
    Response.json({
      gameSystemKey: 'mini',
      version: 3,
      publishedAt: '2026-11-27T10:00:00+00:00',
      content: miniRelease(),
      cardImages: [],
    }),
}
const path = '/studio/game-systems/mini/releases/3/print'

describe('the release print route', () => {
  it('prints the flow, paper and bleed of the address, and marks the shell as chrome to hide', async () => {
    renderAppAt(`${path}?flow=mini&paper=a5&bleed=true&decks=luck`, handlers)

    await screen.findByText(/print on A5 with bleed/)
    expect(document.querySelector('.print-sheet')).toHaveClass('print-a5', 'print-bleed')
    expect(document.querySelector('[data-card="Luck 1, front"]')).not.toBeNull()
    expect(document.querySelector('header[data-app-chrome]')).not.toBeNull()
    expect(document.querySelector('main[data-app-main]')).not.toBeNull()
  })

  it('answers an address without a flow with the flows to choose from, not a default', async () => {
    renderAppAt(path, handlers)

    expect(await screen.findByRole('alert')).toHaveTextContent(/Choose a flow/)
    expect(document.querySelector('[data-card]')).toBeNull()
  })

  it('answers an unknown paper with a message', async () => {
    renderAppAt(`${path}?flow=mini&paper=a3`, handlers)

    expect(await screen.findByRole('alert')).toHaveTextContent(/Unknown paper "a3"/)
  })

  it('sends someone who is not a game manager away, without reading the release', async () => {
    const { fakeApi } = renderAppAt(`${path}?flow=mini`, {
      ...handlers,
      'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
    })

    expect(await screen.findByText('Access denied')).toBeInTheDocument()
    expect(fakeApi.requests).not.toContain(CONTENT)
  })
})
