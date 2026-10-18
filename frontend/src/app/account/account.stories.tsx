import type { Meta, StoryObj } from '@storybook/react-vite'
import { expect, userEvent, within } from 'storybook/test'

import type { FakeHandler } from '@/test/fakeApi'
import { StoryProviders } from '@/test/StoryProviders'

import { AcceptInvitationPage } from './AcceptInvitationPage'
import { ForgotPasswordPage } from './ForgotPasswordPage'
import { ResetPasswordPage } from './ResetPasswordPage'

const json =
  (body: unknown, status = 200): FakeHandler =>
  () =>
    Response.json(body, { status })
const empty =
  (status: number): FakeHandler =>
  () =>
    new Response(null, { status })

const invitation = json({
  email: 'dan@example.com',
  roles: ['SOLO_PLAYER', 'GAME_MANAGER'],
  expiresAt: '2026-10-24T09:00:00+00:00',
})

/** The pages read their token from the URL fragment once, when they mount. */
const withToken = () => {
  window.history.replaceState(null, '', '#token=story-token')
}

const meta = {
  title: 'App/Account pages',
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

export const ForgotPassword: Story = {
  parameters: { api: { 'POST /api/auth/password-reset-requests': empty(202) } },
  render: () => <ForgotPasswordPage />,
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.type(await canvas.findByLabelText('Email'), 'ada@example.com')
    await userEvent.click(canvas.getByRole('button', { name: 'Send reset link' }))
    await expect(await canvas.findByRole('status')).toHaveTextContent('If an account exists')
  },
}

export const ResetPassword: Story = {
  loaders: [withToken],
  render: () => <ResetPasswordPage />,
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await userEvent.type(await canvas.findByLabelText('New password'), 'a-new-password')
    await userEvent.type(canvas.getByLabelText('Confirm password'), 'another-password')
    await userEvent.click(canvas.getByRole('button', { name: 'Change password' }))
    await expect(await canvas.findByRole('alert')).toHaveTextContent('do not match')
  },
}

export const ResetPasswordInvalidLink: Story = {
  loaders: [
    () => {
      window.history.replaceState(null, '', '#')
    },
  ],
  render: () => <ResetPasswordPage />,
  play: async ({ canvasElement }) => {
    await expect(await within(canvasElement).findByRole('alert')).toHaveTextContent(
      'This link is invalid or has expired.',
    )
  },
}

export const AcceptInvitation: Story = {
  loaders: [withToken],
  parameters: { api: { 'POST /api/auth/invitations/lookup': invitation } },
  render: () => <AcceptInvitationPage />,
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement)
    await expect(await canvas.findByText('dan@example.com')).toBeInTheDocument()
    await expect(canvas.getByRole('button', { name: 'Create account' })).toBeEnabled()
  },
}

export const AcceptInvitationExpired: Story = {
  loaders: [withToken],
  parameters: {
    api: {
      'POST /api/auth/invitations/lookup': json(
        { error: 'This link is invalid or has expired.' },
        422,
      ),
    },
  },
  render: () => <AcceptInvitationPage />,
  play: async ({ canvasElement }) => {
    await expect(await within(canvasElement).findByRole('alert')).toHaveTextContent(
      'This link is invalid or has expired.',
    )
  },
}
