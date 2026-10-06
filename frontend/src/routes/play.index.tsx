import { createFileRoute } from '@tanstack/react-router'

import { PlayHomePage } from '@/play/PlayHomePage'

export const Route = createFileRoute('/play/')({
  component: PlayHomePage,
})
