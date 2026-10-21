import { createFileRoute } from '@tanstack/react-router'

import { parseGameSystemTab, type GameSystemTab } from '@/studio/gameSystemTabs'
import { GameSystemPage } from '@/studio/GameSystemPage'

export const Route = createFileRoute('/studio/game-systems/$key')({
  staticData: { layout: 'wide' },
  // The open section. An unknown value must come back as an explicit undefined: the router
  // merges the validated search over the raw one, so omitting the key would keep the raw value.
  validateSearch: (search: Record<string, unknown>): { tab?: GameSystemTab | undefined } => ({
    tab: parseGameSystemTab(search.tab),
  }),
  component: GameSystemRoute,
})

function GameSystemRoute() {
  const { key } = Route.useParams()
  const { tab } = Route.useSearch()
  return <GameSystemPage gameSystemKey={key} tab={tab} />
}
