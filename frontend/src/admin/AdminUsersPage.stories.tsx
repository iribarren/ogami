import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { AdminUsersPage } from './AdminUsersPage'
import { ada, invitations, users } from './fixtures'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })

const meta = {
  title: 'Admin/AdminUsersPage',
  component: AdminUsersPage,
  parameters: {
    api: {
      'GET /api/auth/me': json({ id: ada.id, email: ada.email, roles: ['OWNER'] }),
      'GET /api/admin/users': json(users),
      'GET /api/admin/invitations': json(invitations),
    },
  },
  decorators: [
    (Story, { parameters }) => (
      <StoryProviders handlers={parameters.api as Record<string, FakeHandler>}>
        <Story />
      </StoryProviders>
    ),
  ],
} satisfies Meta<typeof AdminUsersPage>

export default meta
type Story = StoryObj<typeof meta>

export const Populated: Story = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByText('bob@example.com')).toBeInTheDocument()
    await expect(canvas.getByText('Expired')).toBeInTheDocument()
  },
}

export const OnlyYouAndNoInvitations: Story = {
  parameters: {
    api: {
      'GET /api/auth/me': json({ id: ada.id, email: ada.email, roles: ['OWNER'] }),
      'GET /api/admin/users': json([ada]),
      'GET /api/admin/invitations': json([]),
    },
  },
  play: async ({ canvasElement }) => {
    await expect(
      await within(canvasElement).findByText('No pending invitations.'),
    ).toBeInTheDocument()
  },
}

export const LoadError: Story = {
  parameters: {
    api: {
      'GET /api/auth/me': json({ id: ada.id, email: ada.email, roles: ['OWNER'] }),
      'GET /api/admin/users': () => new Response('Oops', { status: 500 }),
      'GET /api/admin/invitations': () => new Response('Oops', { status: 500 }),
    },
  },
  play: async ({ canvasElement }) => {
    await expect(await within(canvasElement).findAllByRole('alert')).toHaveLength(2)
  },
}
