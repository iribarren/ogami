import { createFileRoute } from '@tanstack/react-router'

import { requireRole } from '@/shared/auth/requireRole'
import { StudioHomePage } from '@/studio/StudioHomePage'

export const Route = createFileRoute('/studio')({
  beforeLoad: (options) => requireRole(options, 'GAME_MANAGER'),
  component: StudioHomePage,
})
