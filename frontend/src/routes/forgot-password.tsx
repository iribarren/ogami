import { createFileRoute } from '@tanstack/react-router'

import { ForgotPasswordPage } from '@/app/account/ForgotPasswordPage'

export const Route = createFileRoute('/forgot-password')({
  component: ForgotPasswordPage,
})
