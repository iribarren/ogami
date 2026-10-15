import { createFileRoute } from '@tanstack/react-router'

import { GameSystemPage } from '@/studio/GameSystemPage'

export const Route = createFileRoute('/studio/game-systems/$key')({
  staticData: { layout: 'wide' },
  component: GameSystemRoute,
})

function GameSystemRoute() {
  const { key } = Route.useParams()
  return <GameSystemPage gameSystemKey={key} />
}
