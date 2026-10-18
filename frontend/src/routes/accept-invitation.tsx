import { createFileRoute } from '@tanstack/react-router'

import { AcceptInvitationPage } from '@/app/account/AcceptInvitationPage'

export const Route = createFileRoute('/accept-invitation')({
  component: AcceptInvitationPage,
})
