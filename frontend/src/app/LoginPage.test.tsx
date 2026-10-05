import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { renderAppAt, signedInAs, type FakeHandler } from '@/test/renderApp'

const loginAs =
  (...roles: Parameters<typeof signedInAs>): FakeHandler =>
  async (request) => {
    const body = (await request.json()) as { email: string; password: string }
    if (body.email !== 'ada@example.com' || body.password !== 'secret') {
      return Response.json({ error: 'Invalid credentials.' }, { status: 401 })
    }
    return signedInAs(...roles)(request)
  }

async function signIn(email = 'ada@example.com', password = 'secret') {
  const user = userEvent.setup()
  await user.type(await screen.findByLabelText('Email'), email)
  await user.type(screen.getByLabelText('Password'), password)
  await user.click(screen.getByRole('button', { name: 'Sign in' }))
}

describe('LoginPage', () => {
  it('labels its fields for password managers', async () => {
    renderAppAt('/login')

    expect(await screen.findByLabelText('Email')).toHaveAttribute('autocomplete', 'username')
    expect(screen.getByLabelText('Email')).toHaveAttribute('type', 'email')
    expect(screen.getByLabelText('Password')).toHaveAttribute('autocomplete', 'current-password')
    expect(screen.getByLabelText('Password')).toHaveAttribute('type', 'password')
  })

  it('signs in and returns to the page the visitor asked for', async () => {
    const { router } = renderAppAt('/studio', {
      'POST /api/auth/login': loginAs('GAME_MANAGER'),
    })

    await signIn()

    expect(await screen.findByRole('heading', { level: 1, name: 'Studio' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/studio')
    expect(screen.getByText('ada@example.com')).toBeInTheDocument()
  })

  it('without a redirect, opens the first area the user holds a role for', async () => {
    const { router } = renderAppAt('/login', { 'POST /api/auth/login': loginAs('OWNER') })

    await signIn()

    expect(await screen.findByRole('heading', { level: 1, name: 'Admin' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/admin')
  })

  it('shows the API message when the credentials are wrong', async () => {
    const { router } = renderAppAt('/login', { 'POST /api/auth/login': loginAs('OWNER') })

    await signIn('ada@example.com', 'wrong')

    expect(await screen.findByRole('alert')).toHaveTextContent('Invalid credentials.')
    expect(router.state.location.pathname).toBe('/login')
  })

  it('shows the API message when sign-in is throttled', async () => {
    renderAppAt('/login', {
      'POST /api/auth/login': () =>
        Response.json({ error: 'Too many login attempts. Try again later.' }, { status: 429 }),
    })

    await signIn()

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Too many login attempts. Try again later.',
    )
  })

  it.each(['//evil.com', 'https://evil.com', '/\\evil.com', 'javascript:alert(1)'])(
    'ignores the off-site redirect %s',
    async (redirect) => {
      const { router } = renderAppAt(`/login?redirect=${encodeURIComponent(redirect)}`, {
        'POST /api/auth/login': loginAs('SOLO_PLAYER'),
      })

      await signIn()

      expect(await screen.findByRole('heading', { level: 1, name: 'Play' })).toBeInTheDocument()
      expect(router.state.location.pathname).toBe('/play')
    },
  )

  it('sends signed-in users away from the sign-in page', async () => {
    const { router } = renderAppAt('/login', { 'GET /api/auth/me': signedInAs('SOLO_PLAYER') })

    expect(await screen.findByRole('heading', { level: 1, name: 'Play' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/play')
  })

  it('still renders when the current user cannot be loaded', async () => {
    renderAppAt('/login', {
      'GET /api/auth/me': () => new Response(null, { status: 500 }),
    })

    expect(await screen.findByRole('heading', { level: 1, name: 'Sign in' })).toBeInTheDocument()
    expect(screen.getByLabelText('Email')).toBeInTheDocument()
  })
})
