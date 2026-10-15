import { createFileRoute, Outlet } from '@tanstack/react-router'

import { requireRole } from '@/shared/auth/requireRole'

// Layout of every Studio page: the guard runs before any child route loads.
export const Route = createFileRoute('/studio')({
  beforeLoad: (options) => requireRole(options, 'GAME_MANAGER'),
  component: Outlet,
})
