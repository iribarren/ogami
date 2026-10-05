import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { renderAppAt, signedInAs } from '@/test/renderApp'

describe('AppShell', () => {
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
    renderAppAt('/', { 'GET /api/auth/me': signedInAs('SOLO_PLAYER', 'GAME_MANAGER') })

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
})
