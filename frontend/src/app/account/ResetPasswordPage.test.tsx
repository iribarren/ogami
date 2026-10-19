import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { renderAppAt, type FakeHandler } from '@/test/renderApp'

const invalid = 'This link is invalid or has expired.'
const reset = 'POST /api/auth/password-resets'

async function openLink(fragment: string, handlers: Record<string, FakeHandler> = {}) {
  window.history.replaceState(null, '', `/reset-password${fragment}`)
  const user = userEvent.setup()
  const rendered = renderAppAt('/reset-password', handlers)
  await screen.findByRole('heading', { level: 1, name: 'Reset your password' })
  return { user, ...rendered }
}

async function submit(
  user: ReturnType<typeof userEvent.setup>,
  password: string,
  confirm = password,
) {
  await user.type(screen.getByLabelText('New password'), password)
  await user.type(screen.getByLabelText('Confirm password'), confirm)
  await user.click(screen.getByRole('button', { name: 'Change password' }))
}

afterEach(() => {
  window.history.replaceState(null, '', '/')
})

describe('ResetPasswordPage', () => {
  it('changes the password with the token from the fragment', async () => {
    let body: unknown
    const { user } = await openLink('#token=abc-123_x', {
      [reset]: async (request) => {
        body = await request.json()
        return new Response(null, { status: 204 })
      },
    })

    await submit(user, 'a-new-password')

    expect(await screen.findByText('Your password has been changed.')).toBeInTheDocument()
    expect(body).toEqual({ token: 'abc-123_x', password: 'a-new-password' })
    expect(screen.getByRole('link', { name: 'Go to sign in' })).toHaveAttribute('href', '/login')
  })

  it('removes the fragment from the address bar but keeps the path', async () => {
    await openLink('#token=abc-123_x')

    expect(window.location.hash).toBe('')
    expect(window.location.pathname).toBe('/reset-password')
    expect(window.location.href).not.toContain('abc-123_x')
  })

  it.each(['', '#', '#token=', '#token=%20', '#other=abc'])(
    'shows the invalid link state for the fragment "%s" without calling the API',
    async (fragment) => {
      const { fakeApi } = await openLink(fragment)

      expect(await screen.findByText(invalid)).toBeInTheDocument()
      expect(screen.getByRole('link', { name: 'Ask for a new link' })).toHaveAttribute(
        'href',
        '/forgot-password',
      )
      expect(fakeApi.requests).not.toContain(reset)
    },
  )

  it('shows the invalid link state when the API refuses the token', async () => {
    const { user } = await openLink('#token=old', {
      [reset]: () =>
        Response.json({ error: invalid, code: 'invalid_account_link' }, { status: 422 }),
    })

    await submit(user, 'a-new-password')

    expect(await screen.findByText(invalid)).toBeInTheDocument()
    expect(screen.queryByLabelText('New password')).not.toBeInTheDocument()
  })

  it('shows the API message for a weak password and keeps the form', async () => {
    const { user } = await openLink('#token=abc', {
      [reset]: () =>
        Response.json(
          { error: 'The password must have between 8 and 4096 characters.', code: 'weak_password' },
          { status: 422 },
        ),
    })

    await submit(user, 'short')

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'The password must have between 8 and 4096 characters.',
    )
    expect(screen.getByLabelText('New password')).toBeInTheDocument()
  })

  it('does not treat a 422 as a dead link by its wording: only the code decides', async () => {
    const { user } = await openLink('#token=abc', {
      [reset]: () => Response.json({ error: invalid, code: 'weak_password' }, { status: 422 }),
    })

    await submit(user, 'a-new-password')

    expect(await screen.findByRole('alert')).toHaveTextContent(invalid)
    expect(screen.getByLabelText('New password')).toBeInTheDocument()
  })

  it('fails closed on a 422 without a code: the message shows and the form stays', async () => {
    const { user } = await openLink('#token=abc', {
      [reset]: () => Response.json({ error: 'Something else.' }, { status: 422 }),
    })

    await submit(user, 'a-new-password')

    expect(await screen.findByRole('alert')).toHaveTextContent('Something else.')
    expect(screen.getByLabelText('New password')).toBeInTheDocument()
  })

  it('does not send mismatching passwords', async () => {
    const { user, fakeApi } = await openLink('#token=abc')

    await submit(user, 'a-new-password', 'another-password')

    expect(screen.getByRole('alert')).toHaveTextContent('The passwords do not match.')
    expect(fakeApi.requests).not.toContain(reset)
  })

  it.each([400, 500])('fails visibly on HTTP %i', async (status) => {
    const { user } = await openLink('#token=abc', {
      [reset]: () => new Response('Oops', { status }),
    })

    await submit(user, 'a-new-password')

    expect(await screen.findByRole('alert')).toHaveTextContent(`HTTP ${String(status)}`)
    expect(screen.queryByText('Your password has been changed.')).not.toBeInTheDocument()
  })
})
