import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

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

  it('refuses a flow key the router would read as a number, instead of printing another flow', async () => {
    renderAppAt(`${path}?flow=1e3`, handlers)

    expect(await screen.findByRole('alert')).toHaveTextContent(
      /flow option in the address was read as 1000/,
    )
    expect(document.querySelector('[data-card]')).toBeNull()
  })

  it('answers an unknown paper with a message', async () => {
    renderAppAt(`${path}?flow=mini&paper=a3`, handlers)

    expect(await screen.findByRole('alert')).toHaveTextContent(/Unknown paper "a3"/)
  })

  it('writes a chosen option into the address, replacing the entry instead of adding one', async () => {
    const user = userEvent.setup()
    const { router } = renderAppAt(path, handlers)

    await user.selectOptions(await screen.findByRole('combobox', { name: 'Flow' }), 'mini')
    await user.click(await screen.findByRole('radio', { name: 'A5' }))

    await waitFor(() => {
      expect(router.state.location.search).toEqual({ flow: 'mini', paper: 'a5' })
    })
    expect(router.history.length).toBe(1)
    await screen.findByText(/print on A5/)
    expect(document.querySelector('[data-card]')).not.toBeNull()
  })

  it('keeps a chosen key that looks like a number as text through the address', async () => {
    const user = userEvent.setup()
    const raw = miniRelease()
    Object.assign(raw.flows[0] ?? {}, { key: '1e3' })
    const { router } = renderAppAt(path, {
      ...handlers,
      [CONTENT]: () =>
        Response.json({
          gameSystemKey: 'mini',
          version: 3,
          publishedAt: '2026-11-27T10:00:00+00:00',
          content: raw,
          cardImages: [],
        }),
    })

    await user.selectOptions(await screen.findByRole('combobox', { name: 'Flow' }), '1e3')

    await waitFor(() => {
      expect(router.state.location.search).toEqual({ flow: '1e3' })
    })
    expect(router.state.location.searchStr).toBe('?flow=%221e3%22')
    expect(await screen.findByText(/print on A4/)).toBeInTheDocument()
    expect(screen.queryByRole('alert')).toBeNull()
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
