import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import type { FakeHandler } from '@/test/fakeApi'
import { renderAppAt, signedInAs } from '@/test/renderApp'

import { bob, cleo, dan, erin, invitations, users } from './fixtures'

// Radix checkboxes render a hidden input whose size it measures.
vi.stubGlobal(
  'ResizeObserver',
  class {
    observe = vi.fn()
    unobserve = vi.fn()
    disconnect = vi.fn()
  },
)

const owner = signedInAs('OWNER')

function json(body: unknown, status = 200): FakeHandler {
  return () => Response.json(body, { status })
}

const empty =
  (status: number): FakeHandler =>
  () =>
    new Response(null, { status })
const refused = (error: string, status: number) => json({ error }, status)

async function openAdmin(handlers: Record<string, FakeHandler> = {}) {
  const user = userEvent.setup()
  const rendered = renderAppAt('/admin', {
    'GET /api/auth/me': owner,
    'GET /api/admin/users': json(users),
    'GET /api/admin/invitations': json(invitations),
    ...handlers,
  })
  await screen.findByRole('cell', { name: 'bob@example.com' })
  return { user, ...rendered }
}

const rowOf = (email: string) => {
  const row = screen.getByRole('cell', { name: email }).closest('tr')
  if (row === null) {
    throw new Error(`No table row for ${email}`)
  }
  return within(row)
}
const userRow = rowOf
const invitationRow = rowOf
const dialog = () => within(screen.getByRole('dialog'))
const userPath = (id: string) => `/api/admin/users/${id}`
const invitationPath = (id: string) => `/api/admin/invitations/${id}`

describe('the Users table', () => {
  it('lists every user with roles, status and creation date', async () => {
    await openAdmin()

    expect(screen.getByRole('heading', { level: 1, name: 'Admin' })).toBeInTheDocument()
    const ownerRow = userRow('ada@example.com')
    expect(ownerRow.getByText('Owner')).toBeInTheDocument()
    expect(ownerRow.getByText('Game manager')).toBeInTheDocument()
    expect(ownerRow.getByText('Active')).toBeInTheDocument()
    expect(ownerRow.getByText('1 Oct 2026')).toBeInTheDocument()
    expect(userRow('bob@example.com').getByText('Solo player')).toBeInTheDocument()
    expect(userRow('cleo@example.com').getByText('Disabled')).toBeInTheDocument()
  })

  it('offers Disable, Edit roles and Send reset link on another active user', async () => {
    await openAdmin()

    const row = userRow('bob@example.com')
    expect(row.getByRole('button', { name: 'Edit roles' })).toBeInTheDocument()
    expect(row.getByRole('button', { name: 'Disable' })).toBeInTheDocument()
    expect(row.getByRole('button', { name: 'Send reset link' })).toBeInTheDocument()
  })

  it('gives a disabled user Enable and no reset link', async () => {
    await openAdmin()

    const row = userRow('cleo@example.com')
    expect(row.getByRole('button', { name: 'Enable' })).toBeInTheDocument()
    expect(row.queryByRole('button', { name: 'Disable' })).not.toBeInTheDocument()
    expect(row.queryByRole('button', { name: 'Send reset link' })).not.toBeInTheDocument()
  })

  it('shows loading, empty and error states', async () => {
    renderAppAt('/admin', {
      'GET /api/auth/me': owner,
      'GET /api/admin/users': () => new Response('Oops', { status: 500 }),
      'GET /api/admin/invitations': json([]),
    })

    expect(await screen.findByText('Loading the users…')).toBeInTheDocument()
    expect(await screen.findByText('No pending invitations.')).toBeInTheDocument()
    expect(await screen.findByRole('alert', {}, { timeout: 3000 })).toHaveTextContent(
      'Loading the users failed with HTTP 500.',
    )
  })
})

describe('the own account', () => {
  it('has no Disable action', async () => {
    await openAdmin()

    const row = userRow('ada@example.com')
    expect(row.getByRole('button', { name: 'Edit roles' })).toBeInTheDocument()
    expect(row.queryByRole('button', { name: 'Disable' })).not.toBeInTheDocument()
  })

  it('keeps OWNER checked and locked in the roles dialog', async () => {
    const { user } = await openAdmin()

    await user.click(userRow('ada@example.com').getByRole('button', { name: 'Edit roles' }))

    expect(dialog().getByRole('checkbox', { name: 'Owner' })).toBeChecked()
    expect(dialog().getByRole('checkbox', { name: 'Owner' })).toBeDisabled()
    expect(dialog().getByRole('checkbox', { name: 'Game manager' })).toBeEnabled()
  })
})

describe('the Edit roles dialog', () => {
  it('saves the selected roles and refreshes the list', async () => {
    let body: unknown
    const { user, fakeApi } = await openAdmin({
      [`PUT ${userPath(bob.id)}/roles`]: async (request) => {
        body = await request.json()
        return Response.json({ ...bob, roles: ['SOLO_PLAYER', 'GAME_MANAGER'] })
      },
    })

    await user.click(userRow('bob@example.com').getByRole('button', { name: 'Edit roles' }))
    expect(dialog().getByRole('checkbox', { name: 'Solo player' })).toBeChecked()
    await user.click(dialog().getByRole('checkbox', { name: 'Game manager' }))
    await user.click(dialog().getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
    expect(body).toEqual({ roles: ['SOLO_PLAYER', 'GAME_MANAGER'] })
    expect(fakeApi.requests.filter((key) => key === 'GET /api/admin/users')).toHaveLength(2)
  })

  it('cannot save without a role', async () => {
    const { user } = await openAdmin()

    await user.click(userRow('bob@example.com').getByRole('button', { name: 'Edit roles' }))
    await user.click(dialog().getByRole('checkbox', { name: 'Solo player' }))

    expect(dialog().getByRole('button', { name: 'Save' })).toBeDisabled()
    expect(dialog().getByText('Choose at least one role.')).toBeInTheDocument()
  })

  it.each([
    [
      409,
      'You cannot remove the OWNER role from your own account.',
      'Your own account is protected.',
    ],
    [
      409,
      'The OWNER role cannot be removed from the last active owner.',
      'The last active owner cannot be disabled or lose the OWNER role.',
    ],
    [422, '"ROOT" is not a role.', '"ROOT" is not a role.'],
  ])('explains a %i refusal inside the dialog', async (status, error, message) => {
    const { user } = await openAdmin({
      [`PUT ${userPath(bob.id)}/roles`]: refused(error, status),
    })

    await user.click(userRow('bob@example.com').getByRole('button', { name: 'Edit roles' }))
    await user.click(dialog().getByRole('checkbox', { name: 'Game manager' }))
    await user.click(dialog().getByRole('button', { name: 'Save' }))

    expect(await dialog().findByRole('alert')).toHaveTextContent(message)
  })

  it('says the user is gone on a 404 and refreshes the list', async () => {
    const { user, fakeApi } = await openAdmin({
      [`PUT ${userPath(bob.id)}/roles`]: refused('No user with id "x".', 404),
    })

    await user.click(userRow('bob@example.com').getByRole('button', { name: 'Edit roles' }))
    await user.click(dialog().getByRole('checkbox', { name: 'Game manager' }))
    await user.click(dialog().getByRole('button', { name: 'Save' }))

    expect(await dialog().findByRole('alert')).toHaveTextContent('This user no longer exists.')
    await waitFor(() => {
      expect(fakeApi.requests.filter((key) => key === 'GET /api/admin/users')).toHaveLength(2)
    })
  })
})

describe('disabling and enabling', () => {
  it('disables after a confirmation and refreshes the list', async () => {
    const { user, fakeApi } = await openAdmin({ [`POST ${userPath(bob.id)}/disable`]: empty(204) })

    await user.click(userRow('bob@example.com').getByRole('button', { name: 'Disable' }))
    expect(fakeApi.requests).not.toContain(`POST ${userPath(bob.id)}/disable`)
    expect(dialog().getByText(/bob@example.com/)).toBeInTheDocument()
    await user.click(dialog().getByRole('button', { name: 'Disable' }))

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
    expect(fakeApi.requests).toContain(`POST ${userPath(bob.id)}/disable`)
    expect(fakeApi.requests.filter((key) => key === 'GET /api/admin/users')).toHaveLength(2)
  })

  it('does nothing when the confirmation is cancelled', async () => {
    const { user, fakeApi } = await openAdmin()

    await user.click(userRow('bob@example.com').getByRole('button', { name: 'Disable' }))
    await user.click(dialog().getByRole('button', { name: 'Cancel' }))

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(fakeApi.requests.filter((key) => key.startsWith('POST'))).toEqual([])
  })

  it('enables after a confirmation', async () => {
    const { user, fakeApi } = await openAdmin({ [`POST ${userPath(cleo.id)}/enable`]: empty(204) })

    await user.click(userRow('cleo@example.com').getByRole('button', { name: 'Enable' }))
    await user.click(dialog().getByRole('button', { name: 'Enable' }))

    await waitFor(() => {
      expect(fakeApi.requests).toContain(`POST ${userPath(cleo.id)}/enable`)
    })
  })

  it('explains that the last active owner is protected', async () => {
    const { user } = await openAdmin({
      [`POST ${userPath(bob.id)}/disable`]: refused(
        'The last active owner cannot be disabled.',
        409,
      ),
    })

    await user.click(userRow('bob@example.com').getByRole('button', { name: 'Disable' }))
    await user.click(dialog().getByRole('button', { name: 'Disable' }))

    expect(await dialog().findByRole('alert')).toHaveTextContent(
      'The last active owner cannot be disabled or lose the OWNER role.',
    )
  })

  it('says the user is gone on a 404 and refreshes the list', async () => {
    const { user, fakeApi } = await openAdmin({
      [`POST ${userPath(bob.id)}/disable`]: refused('No user with id "x".', 404),
    })

    await user.click(userRow('bob@example.com').getByRole('button', { name: 'Disable' }))
    await user.click(dialog().getByRole('button', { name: 'Disable' }))

    expect(await dialog().findByRole('alert')).toHaveTextContent('This user no longer exists.')
    await waitFor(() => {
      expect(fakeApi.requests.filter((key) => key === 'GET /api/admin/users')).toHaveLength(2)
    })
  })

  it('shows a generic message for any other failure', async () => {
    const { user } = await openAdmin({ [`POST ${userPath(bob.id)}/disable`]: empty(500) })

    await user.click(userRow('bob@example.com').getByRole('button', { name: 'Disable' }))
    await user.click(dialog().getByRole('button', { name: 'Disable' }))

    expect(await dialog().findByRole('alert')).toHaveTextContent(
      'Disabling the user failed with HTTP 500.',
    )
  })
})

describe('sending a reset link', () => {
  const path = `POST ${userPath(bob.id)}/password-reset`

  it('sends it directly and confirms', async () => {
    const { user } = await openAdmin({ [path]: empty(202) })

    await user.click(userRow('bob@example.com').getByRole('button', { name: 'Send reset link' }))

    expect(await screen.findByRole('status')).toHaveTextContent(
      'Reset link sent to bob@example.com',
    )
  })

  it('says the email could not be sent on a 503', async () => {
    const { user } = await openAdmin({ [path]: refused('The email could not be sent.', 503) })

    await user.click(userRow('bob@example.com').getByRole('button', { name: 'Send reset link' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'The email could not be sent. Try again later.',
    )
  })

  it('says the user is disabled on a 409', async () => {
    const { user } = await openAdmin({ [path]: refused('The user "x" is disabled.', 409) })

    await user.click(userRow('bob@example.com').getByRole('button', { name: 'Send reset link' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'This user is disabled. Enable the account first.',
    )
  })

  it('says the user is gone on a 404 and refreshes the list', async () => {
    const { user, fakeApi } = await openAdmin({ [path]: refused('No user with id "x".', 404) })

    await user.click(userRow('bob@example.com').getByRole('button', { name: 'Send reset link' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('This user no longer exists.')
    await waitFor(() => {
      expect(fakeApi.requests.filter((key) => key === 'GET /api/admin/users')).toHaveLength(2)
    })
  })
})

describe('the Invitations section', () => {
  it('lists pending invitations with roles, expiry and an Expired badge', async () => {
    await openAdmin()

    const pending = invitationRow('dan@example.com')
    expect(pending.getByText('Solo player')).toBeInTheDocument()
    expect(pending.getByText('Game manager')).toBeInTheDocument()
    expect(pending.getByText('24 Oct 2026')).toBeInTheDocument()
    expect(pending.queryByText('Expired')).not.toBeInTheDocument()
    expect(invitationRow('erin@example.com').getByText('Expired')).toBeInTheDocument()
  })

  it('shows why the invitations could not be loaded', async () => {
    renderAppAt('/admin', {
      'GET /api/auth/me': owner,
      'GET /api/admin/users': json(users),
      'GET /api/admin/invitations': () => new Response('Oops', { status: 500 }),
    })

    const section = within(await screen.findByRole('region', { name: 'Invitations' }))
    expect(await section.findByRole('alert', {}, { timeout: 3000 })).toHaveTextContent(
      'Loading the invitations failed with HTTP 500.',
    )
  })

  it('resends an invitation and confirms', async () => {
    const { user, fakeApi } = await openAdmin({
      [`POST ${invitationPath(erin.id)}/resend`]: empty(202),
    })

    await user.click(invitationRow('erin@example.com').getByRole('button', { name: 'Resend' }))

    expect(await screen.findByRole('status')).toHaveTextContent(
      'Invitation sent to erin@example.com',
    )
    await waitFor(() => {
      expect(fakeApi.requests.filter((key) => key === 'GET /api/admin/invitations')).toHaveLength(2)
    })
  })

  it('says the email could not be sent when a resend gets a 503', async () => {
    const { user } = await openAdmin({
      [`POST ${invitationPath(erin.id)}/resend`]: refused('The email could not be sent.', 503),
    })

    await user.click(invitationRow('erin@example.com').getByRole('button', { name: 'Resend' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'The email could not be sent. Try again later.',
    )
  })

  it('revokes after a confirmation', async () => {
    const { user, fakeApi } = await openAdmin({
      [`DELETE ${invitationPath(dan.id)}`]: empty(204),
    })

    await user.click(invitationRow('dan@example.com').getByRole('button', { name: 'Revoke' }))
    expect(fakeApi.requests).not.toContain(`DELETE ${invitationPath(dan.id)}`)
    await user.click(dialog().getByRole('button', { name: 'Revoke' }))

    await waitFor(() => {
      expect(fakeApi.requests).toContain(`DELETE ${invitationPath(dan.id)}`)
    })
    await waitFor(() => {
      expect(fakeApi.requests.filter((key) => key === 'GET /api/admin/invitations')).toHaveLength(2)
    })
  })

  it('says the invitation is gone on a 404 and refreshes the list', async () => {
    const { user, fakeApi } = await openAdmin({
      [`DELETE ${invitationPath(dan.id)}`]: refused('No pending invitation with id "x".', 404),
    })

    await user.click(invitationRow('dan@example.com').getByRole('button', { name: 'Revoke' }))
    await user.click(dialog().getByRole('button', { name: 'Revoke' }))

    expect(await dialog().findByRole('alert')).toHaveTextContent(
      'This invitation no longer exists.',
    )
    await waitFor(() => {
      expect(fakeApi.requests.filter((key) => key === 'GET /api/admin/invitations')).toHaveLength(2)
    })
  })
})

describe('the Invite dialog', () => {
  async function fillInvite(user: ReturnType<typeof userEvent.setup>, email: string) {
    await user.click(screen.getByRole('button', { name: 'Invite user' }))
    await user.type(dialog().getByLabelText('Email'), email)
    await user.click(dialog().getByRole('checkbox', { name: 'Game manager' }))
  }

  it('invites with the chosen roles and refreshes the list', async () => {
    let body: unknown
    const { user, fakeApi } = await openAdmin({
      'POST /api/admin/invitations': async (request) => {
        body = await request.json()
        return Response.json(dan, { status: 201 })
      },
    })

    await fillInvite(user, 'fay@example.com')
    await user.click(dialog().getByRole('button', { name: 'Send invitation' }))

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
    expect(body).toEqual({ email: 'fay@example.com', roles: ['GAME_MANAGER'] })
    expect(await screen.findByRole('status')).toHaveTextContent(
      'Invitation sent to fay@example.com',
    )
    expect(fakeApi.requests.filter((key) => key === 'GET /api/admin/invitations')).toHaveLength(2)
  })

  it('needs an email and at least one role', async () => {
    const { user } = await openAdmin()

    await user.click(screen.getByRole('button', { name: 'Invite user' }))
    expect(dialog().getByRole('button', { name: 'Send invitation' })).toBeDisabled()
    await user.type(dialog().getByLabelText('Email'), 'fay@example.com')
    expect(dialog().getByRole('button', { name: 'Send invitation' })).toBeDisabled()
    await user.click(dialog().getByRole('checkbox', { name: 'Solo player' }))
    expect(dialog().getByRole('button', { name: 'Send invitation' })).toBeEnabled()
    await user.click(dialog().getByRole('checkbox', { name: 'Solo player' }))
    expect(dialog().getByRole('button', { name: 'Send invitation' })).toBeDisabled()
  })

  it.each([
    [
      'A user with email "fay@example.com" already exists.',
      'A user with this email already exists.',
    ],
    [
      '"fay@example.com" already has a pending invitation.',
      'This email already has a pending invitation.',
    ],
  ])('explains a 409 (%s)', async (error, message) => {
    const { user } = await openAdmin({ 'POST /api/admin/invitations': refused(error, 409) })

    await fillInvite(user, 'fay@example.com')
    await user.click(dialog().getByRole('button', { name: 'Send invitation' }))

    expect(await dialog().findByRole('alert')).toHaveTextContent(message)
  })

  it('shows the field message of a 422', async () => {
    const { user } = await openAdmin({
      'POST /api/admin/invitations': refused('"fay" is not a valid email address.', 422),
    })

    await fillInvite(user, 'fay')
    await user.click(dialog().getByRole('button', { name: 'Send invitation' }))

    expect(await dialog().findByRole('alert')).toHaveTextContent(
      '"fay" is not a valid email address.',
    )
  })

  it('tells the owner to use Resend on a 503 and refreshes the list', async () => {
    const { user, fakeApi } = await openAdmin({
      'POST /api/admin/invitations': refused('The email could not be sent.', 503),
    })

    await fillInvite(user, 'fay@example.com')
    await user.click(dialog().getByRole('button', { name: 'Send invitation' }))

    expect(await dialog().findByRole('alert')).toHaveTextContent(
      'The invitation was saved, but the email could not be sent. Use Resend.',
    )
    await waitFor(() => {
      expect(fakeApi.requests.filter((key) => key === 'GET /api/admin/invitations')).toHaveLength(2)
    })
  })
})
