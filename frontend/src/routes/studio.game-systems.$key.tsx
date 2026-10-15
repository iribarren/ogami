import { createFileRoute } from '@tanstack/react-router'

export const Route = createFileRoute('/studio/game-systems/$key')({
  staticData: { layout: 'wide' },
  component: GameSystemRoute,
})

function GameSystemRoute() {
  const { key } = Route.useParams()
  return <p>{key}</p>
}
