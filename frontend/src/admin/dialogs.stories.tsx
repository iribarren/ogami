import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, fn, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { ConfirmDialog } from './ConfirmDialog'
import { EditRolesDialog } from './EditRolesDialog'
import { ada, bob } from './fixtures'
import { InviteDialog } from './InviteDialog'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

const meta = {
  title: 'Admin/Dialogs',
  parameters: { api: {} },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta

export default meta
type Story = StoryObj<typeof meta>

const dialog = () => within(within(document.body).getByRole('dialog'))

export const EditRoles: Story = {
  render: () => <EditRolesDialog user={bob} isSelf={false} onClose={fn()} />,
  play: async () => {
    await userEvent.click(
      await within(document.body).findByRole('checkbox', { name: 'Solo player' }),
    )
    await expect(dialog().getByRole('button', { name: 'Save' })).toBeDisabled()
  },
}

export const EditOwnRoles: Story = {
  render: () => <EditRolesDialog user={ada} isSelf onClose={fn()} />,
  play: async () => {
    await expect(
      await within(document.body).findByRole('checkbox', { name: 'Owner' }),
    ).toBeDisabled()
  },
}

export const Confirm: Story = {
  render: () => (
    <ConfirmDialog
      title="Disable user"
      description="bob@example.com will be signed out."
      confirmLabel="Disable"
      pending={false}
      error={undefined}
      onConfirm={fn()}
      onClose={fn()}
    />
  ),
}

export const ConfirmRefused: Story = {
  render: () => (
    <ConfirmDialog
      title="Disable user"
      description="bob@example.com will be signed out."
      confirmLabel="Disable"
      pending={false}
      error="The last active owner cannot be disabled or lose the OWNER role."
      onConfirm={fn()}
      onClose={fn()}
    />
  ),
}

export const Invite: Story = {
  render: () => <InviteDialog onInvited={fn()} />,
  play: async ({ canvasElement }) => {
    await userEvent.click(await within(canvasElement).findByRole('button', { name: 'Invite user' }))
    await expect(dialog().getByRole('button', { name: 'Send invitation' })).toBeDisabled()
  },
}

export const InviteEmailNotSent: Story = {
  parameters: {
    api: { 'POST /api/admin/invitations': json({ error: 'The email could not be sent.' }, 503) },
  },
  render: () => <InviteDialog onInvited={fn()} />,
  play: async ({ canvasElement }) => {
    await userEvent.click(await within(canvasElement).findByRole('button', { name: 'Invite user' }))
    await userEvent.type(dialog().getByLabelText('Email'), 'fay@example.com')
    await userEvent.click(dialog().getByRole('checkbox', { name: 'Solo player' }))
    await userEvent.click(dialog().getByRole('button', { name: 'Send invitation' }))
    await expect(await dialog().findByRole('alert')).toHaveTextContent('Use Resend.')
  },
}
