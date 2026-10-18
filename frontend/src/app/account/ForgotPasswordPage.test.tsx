import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { renderAppAt, type FakeHandler } from '@/test/renderApp'

const neutral = 'If an account exists for that email, we sent a link to reset your password.'

async function ask(handler: FakeHandler, email = 'ada@example.com') {
  const user = userEvent.setup()
  const rendered = renderAppAt('/forgot-password', {
    'POST /api/auth/password-reset-requests': handler,
  })
  await user.type(await screen.findByLabelText('Email'), email)
  await user.click(screen.getByRole('button', { name: 'Send reset link' }))
  return rendered
}

describe('ForgotPasswordPage', () => {
  it('sends the email and shows the same neutral message', async () => {
    let body: unknown
    await ask(async (request) => {
      body = await request.json()
      return new Response(null, { status: 202 })
    })

    expect(await screen.findByText(neutral)).toBeInTheDocument()
    expect(body).toEqual({ email: 'ada@example.com' })
    expect(screen.queryByLabelText('Email')).not.toBeInTheDocument()
  })

  it('shows the API message for an address it refuses', async () => {
    await ask(() => Response.json({ error: 'The email is not valid.' }, { status: 422 }), 'a@b')

    expect(await screen.findByRole('alert')).toHaveTextContent('The email is not valid.')
    expect(screen.queryByText(neutral)).not.toBeInTheDocument()
  })

  it.each([400, 500])('fails visibly on HTTP %i', async (status) => {
    await ask(() => new Response('Oops', { status }))

    expect(await screen.findByRole('alert')).toHaveTextContent(`HTTP ${String(status)}`)
    expect(screen.queryByText(neutral)).not.toBeInTheDocument()
  })

  it('links back to sign in', async () => {
    renderAppAt('/forgot-password')

    expect(await screen.findByRole('link', { name: 'Back to sign in' })).toHaveAttribute(
      'href',
      '/login',
    )
  })
})
