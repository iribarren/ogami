import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { renderAppAt, signedInAs, type FakeHandler } from '@/test/renderApp'

const lookup = 'POST /api/auth/invitations/lookup'
const accept = 'POST /api/auth/invitations/accept'
const login = 'POST /api/auth/login'
const invalid = 'This link is invalid or has expired.'

const invitation: FakeHandler = () =>
  Response.json({
    email: 'dan@example.com',
    roles: ['SOLO_PLAYER', 'GAME_MANAGER'],
    expiresAt: '2026-10-24T09:00:00+00:00',
  })
const noContent: FakeHandler = () => new Response(null, { status: 204 })

async function openLink(fragment: string, handlers: Record<string, FakeHandler> = {}) {
  window.history.replaceState(null, '', `/accept-invitation${fragment}`)
  const user = userEvent.setup()
  const rendered = renderAppAt('/accept-invitation', handlers)
  await screen.findByRole('heading', { level: 1, name: 'Accept your invitation' })
  return { user, ...rendered }
}

async function submit(
  user: ReturnType<typeof userEvent.setup>,
  password: string,
  confirm = password,
) {
  await user.type(await screen.findByLabelText('New password'), password)
  await user.type(screen.getByLabelText('Confirm password'), confirm)
  await user.click(screen.getByRole('button', { name: 'Create account' }))
}

afterEach(() => {
  window.history.replaceState(null, '', '/')
})

describe('AcceptInvitationPage', () => {
  it('shows who the invitation is for, creates the account and signs in as that user', async () => {
    let lookupBody: unknown
    let acceptBody: unknown
    let loginBody: unknown
    const { user, router } = await openLink('#token=abc-123_x', {
      [lookup]: async (request) => {
        lookupBody = await request.json()
        return invitation(request)
      },
      [accept]: async (request) => {
        acceptBody = await request.json()
        return noContent(request)
      },
      [login]: async (request) => {
        loginBody = await request.json()
        return Response.json({ id: 'u', email: 'dan@example.com', roles: ['SOLO_PLAYER'] })
      },
      'GET /api/campaigns': () => Response.json([]),
    })

    expect(await screen.findByText('dan@example.com')).toBeInTheDocument()
    expect(screen.getByText(/Solo player, Game manager/)).toBeInTheDocument()
    await submit(user, 'a-new-password')

    expect(await screen.findByRole('heading', { level: 1, name: 'Play' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/play')
    expect(lookupBody).toEqual({ token: 'abc-123_x' })
    expect(acceptBody).toEqual({ token: 'abc-123_x', password: 'a-new-password' })
    expect(loginBody).toEqual({ email: 'dan@example.com', password: 'a-new-password' })
  })

  it('removes the fragment from the address bar but keeps the path', async () => {
    await openLink('#token=abc-123_x', { [lookup]: invitation })
    await screen.findByText('dan@example.com')

    expect(window.location.hash).toBe('')
    expect(window.location.pathname).toBe('/accept-invitation')
  })

  it.each(['', '#token=', '#other=abc'])(
    'shows the invalid link state for the fragment "%s" without calling the API',
    async (fragment) => {
      const { fakeApi } = await openLink(fragment)

      expect(await screen.findByText(/This link is invalid or has expired/)).toBeInTheDocument()
      expect(fakeApi.requests).not.toContain(lookup)
    },
  )

  it('shows the invalid link state when the lookup refuses the token', async () => {
    await openLink('#token=old', {
      [lookup]: () =>
        Response.json({ error: invalid, code: 'invalid_account_link' }, { status: 422 }),
    })

    expect(await screen.findByText(/This link is invalid or has expired/)).toBeInTheDocument()
    expect(screen.queryByLabelText('New password')).not.toBeInTheDocument()
  })

  it('fails closed on an unreadable lookup answer', async () => {
    await openLink('#token=abc', { [lookup]: () => Response.json({ hello: 'world' }) })

    expect(await screen.findByRole('alert')).toHaveTextContent('The invitation could not be read.')
    expect(screen.queryByLabelText('New password')).not.toBeInTheDocument()
  })

  it.each([400, 500])('fails visibly when the lookup answers HTTP %i', async (status) => {
    await openLink('#token=abc', { [lookup]: () => new Response('Oops', { status }) })

    expect(await screen.findByRole('alert')).toHaveTextContent(`HTTP ${String(status)}`)
    expect(screen.queryByLabelText('New password')).not.toBeInTheDocument()
  })

  it('shows the API message for a weak password and creates no session', async () => {
    const { user, fakeApi } = await openLink('#token=abc', {
      [lookup]: invitation,
      [accept]: () =>
        Response.json(
          { error: 'The password must have between 8 and 4096 characters.', code: 'weak_password' },
          { status: 422 },
        ),
    })

    await submit(user, 'short')

    expect(await screen.findByRole('alert')).toHaveTextContent('between 8 and 4096 characters')
    expect(fakeApi.requests).not.toContain(login)
  })

  it('shows the invalid link state when accepting is refused for the link', async () => {
    const { user } = await openLink('#token=abc', {
      [lookup]: invitation,
      [accept]: () =>
        Response.json({ error: invalid, code: 'invalid_account_link' }, { status: 422 }),
    })

    await submit(user, 'a-new-password')

    expect(await screen.findByText(/This link is invalid or has expired/)).toBeInTheDocument()
  })

  it('shows the API message when the email already has an account', async () => {
    const { user } = await openLink('#token=abc', {
      [lookup]: invitation,
      [accept]: () =>
        Response.json(
          {
            error: 'A user with email "dan@example.com" already exists.',
            code: 'email_already_in_use',
          },
          { status: 409 },
        ),
    })

    await submit(user, 'a-new-password')

    expect(await screen.findByRole('alert')).toHaveTextContent('already exists')
  })

  it('does not send mismatching passwords', async () => {
    const { user, fakeApi } = await openLink('#token=abc', { [lookup]: invitation })

    await submit(user, 'a-new-password', 'another-password')

    expect(screen.getByRole('alert')).toHaveTextContent('The passwords do not match.')
    expect(fakeApi.requests).not.toContain(accept)
  })

  it('keeps the account created state when sign-in fails and never accepts twice', async () => {
    const { user, fakeApi } = await openLink('#token=abc', {
      [lookup]: invitation,
      [accept]: noContent,
      [login]: () => Response.json({ error: 'Too many login attempts.' }, { status: 429 }),
    })

    await submit(user, 'a-new-password')

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Your account was created, but signing in failed: Too many login attempts.',
    )
    expect(screen.getByRole('link', { name: 'Go to sign in' })).toHaveAttribute('href', '/login')
    expect(screen.queryByLabelText('New password')).not.toBeInTheDocument()
    expect(screen.queryByText(/This link is invalid or has expired/)).not.toBeInTheDocument()
    expect(fakeApi.requests.filter((key) => key === accept)).toHaveLength(1)
  })

  it('signs in as the invited user even when someone else is signed in', async () => {
    let signedIn = signedInAs('OWNER')
    const { user } = await openLink('#token=abc', {
      'GET /api/auth/me': (request) => signedIn(request),
      [lookup]: invitation,
      [accept]: noContent,
      [login]: () => {
        signedIn = () =>
          Response.json({ id: 'u', email: 'dan@example.com', roles: ['SOLO_PLAYER'] })
        return signedIn(new Request('http://ogami.test'))
      },
      'GET /api/campaigns': () => Response.json([]),
    })

    await submit(user, 'a-new-password')

    expect(await screen.findByRole('heading', { level: 1, name: 'Play' })).toBeInTheDocument()
    expect(await screen.findByText('dan@example.com')).toBeInTheDocument()
  })
})
