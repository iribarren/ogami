import { createFileRoute, Outlet } from '@tanstack/react-router'

import { requireRole } from '@/shared/auth/requireRole'

// Layout of every Play page: the guard runs before any child route loads.
export const Route = createFileRoute('/play')({
  beforeLoad: (options) => requireRole(options, 'SOLO_PLAYER'),
  component: Outlet,
})
