import { createFileRoute } from '@tanstack/react-router'

import { AdminUsersPage } from '@/admin/AdminUsersPage'
import { requireRole } from '@/shared/auth/requireRole'

export const Route = createFileRoute('/admin')({
  beforeLoad: (options) => requireRole(options, 'OWNER'),
  component: AdminUsersPage,
})
