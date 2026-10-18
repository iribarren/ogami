import { createFileRoute } from '@tanstack/react-router'

import { ResetPasswordPage } from '@/app/account/ResetPasswordPage'

export const Route = createFileRoute('/reset-password')({
  component: ResetPasswordPage,
})
