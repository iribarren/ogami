import { createFileRoute } from '@tanstack/react-router'

import { PlayHomePage } from '@/play/PlayHomePage'
import { requireRole } from '@/shared/auth/requireRole'

export const Route = createFileRoute('/play')({
  beforeLoad: (options) => requireRole(options, 'SOLO_PLAYER'),
  component: PlayHomePage,
})
