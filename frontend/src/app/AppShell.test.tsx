import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { renderAppAt, signedInAs } from '@/test/renderApp'

describe('AppShell', () => {
  beforeEach(() => {
    localStorage.clear()
    document.documentElement.classList.remove('dark')
  })

  it('offers only a sign-in link to anonymous visitors', async () => {
    renderAppAt('/')

    const nav = await screen.findByRole('navigation', { name: 'Main' })
    expect(await within(nav).findByRole('link', { name: 'Sign in' })).toHaveAttribute(
      'href',
      '/login',
    )
    expect(within(nav).queryByRole('link', { name: 'Play' })).not.toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Studio' })).not.toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Admin' })).not.toBeInTheDocument()
  })

  it('links every area the signed-in user holds a role for', async () => {
    renderAppAt('/', { 'GET /api/auth/me': signedInAs('SOLO_PLAYER', 'GAME_MANAGER', 'OWNER') })

    const nav = await screen.findByRole('navigation', { name: 'Main' })
    expect(await within(nav).findByRole('link', { name: 'Play' })).toHaveAttribute('href', '/play')
    expect(within(nav).getByRole('link', { name: 'Studio' })).toHaveAttribute('href', '/studio')
    expect(within(nav).getByRole('link', { name: 'Admin' })).toHaveAttribute('href', '/admin')
    expect(within(nav).queryByRole('link', { name: 'Sign in' })).not.toBeInTheDocument()
    expect(screen.getByText('ada@example.com')).toBeInTheDocument()
  })

  it('hides the areas the signed-in user has no role for', async () => {
    renderAppAt('/', { 'GET /api/auth/me': signedInAs('GAME_MANAGER') })

    const nav = await screen.findByRole('navigation', { name: 'Main' })
    expect(await within(nav).findByRole('link', { name: 'Studio' })).toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Play' })).not.toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Admin' })).not.toBeInTheDocument()
  })

  it('opens an area and marks its link as the current page', async () => {
    const user = userEvent.setup()
    renderAppAt('/', {
      'GET /api/auth/me': signedInAs('SOLO_PLAYER', 'GAME_MANAGER'),
      'GET /api/studio/game-systems': () => Response.json([]),
    })

    const nav = await screen.findByRole('navigation', { name: 'Main' })
    await user.click(await within(nav).findByRole('link', { name: 'Studio' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'Studio' })).toBeInTheDocument()
    expect(within(nav).getByRole('link', { name: 'Studio' })).toHaveAttribute(
      'aria-current',
      'page',
    )
    expect(within(nav).getByRole('link', { name: 'Play' })).not.toHaveAttribute('aria-current')
  })

  it('signs out, forgets the user and goes to the sign-in page', async () => {
    const user = userEvent.setup()
    const { router, queryClient, fakeApi } = renderAppAt('/play', {
      'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
      'POST /api/auth/logout': () => new Response(null, { status: 204 }),
    })
    queryClient.setQueryData(['campaigns'], ['A user-scoped campaign'])

    await user.click(await screen.findByRole('button', { name: 'Sign out' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'Sign in' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/login')
    expect(fakeApi.requests).toContain('POST /api/auth/logout')
    expect(screen.queryByText('ada@example.com')).not.toBeInTheDocument()
    const nav = screen.getByRole('navigation', { name: 'Main' })
    expect(within(nav).queryByRole('link', { name: 'Play' })).not.toBeInTheDocument()
    expect(queryClient.getQueryData(['campaigns'])).toBeUndefined()
  })

  it('treats a session that is already gone as signed out', async () => {
    const user = userEvent.setup()
    const { router } = renderAppAt('/play', {
      'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
      'POST /api/auth/logout': () =>
        Response.json({ error: 'Authentication required.' }, { status: 401 }),
    })

    await user.click(await screen.findByRole('button', { name: 'Sign out' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'Sign in' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/login')
    expect(screen.queryByText('ada@example.com')).not.toBeInTheDocument()
  })

  it('reports a failed sign-out and keeps the user signed in', async () => {
    const user = userEvent.setup()
    const { router } = renderAppAt('/play', {
      'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
      'POST /api/auth/logout': () => new Response(null, { status: 500 }),
    })

    await user.click(await screen.findByRole('button', { name: 'Sign out' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Sign-out failed')
    expect(screen.getByText('ada@example.com')).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/play')
  })

  it('switches the color mode from the header and remembers it', async () => {
    const user = userEvent.setup()
    renderAppAt('/')

    const toggle = await screen.findByRole('combobox', { name: 'Color mode' })
    expect(toggle).toHaveValue('system')

    await user.selectOptions(toggle, 'Dark')

    expect(document.documentElement).toHaveClass('dark')
    expect(localStorage.getItem('ogami.colorMode')).toBe('dark')

    await user.selectOptions(toggle, 'Light')

    expect(document.documentElement).not.toHaveClass('dark')
    expect(localStorage.getItem('ogami.colorMode')).toBe('light')
  })

  it('starts the toggle on the stored color mode', async () => {
    localStorage.setItem('ogami.colorMode', 'dark')
    renderAppAt('/')

    expect(await screen.findByRole('combobox', { name: 'Color mode' })).toHaveValue('dark')
    expect(document.documentElement).toHaveClass('dark')
  })

  describe('container width', () => {
    const widths = () => ({
      header: screen.getByRole('banner').firstElementChild,
      main: screen.getByRole('main'),
    })

    it('uses the default width on a regular page', async () => {
      renderAppAt('/')

      await screen.findByRole('navigation', { name: 'Main' })
      expect(widths().header).toHaveClass('max-w-5xl')
      expect(widths().main).toHaveClass('max-w-5xl')
    })

    it('widens header and content together on a route that asks for the wide layout', async () => {
      const campaignId = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'
      renderAppAt(`/play/campaigns/${campaignId}`, {
        'GET /api/auth/me': signedInAs('SOLO_PLAYER'),
        [`GET /api/campaigns/${campaignId}`]: () =>
          Response.json({ error: 'Not found.' }, { status: 404 }),
      })

      await screen.findByRole('heading', { level: 1, name: 'Campaign not found' })
      expect(widths().header).toHaveClass('max-w-7xl')
      expect(widths().main).toHaveClass('max-w-7xl')
      expect(widths().main).not.toHaveClass('max-w-5xl')
    })
  })
})
