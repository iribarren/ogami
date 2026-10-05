import { createFileRoute } from '@tanstack/react-router'

import { AdminHomePage } from '@/admin/AdminHomePage'
import { requireRole } from '@/shared/auth/requireRole'

export const Route = createFileRoute('/admin')({
  beforeLoad: (options) => requireRole(options, 'OWNER'),
  component: AdminHomePage,
})
