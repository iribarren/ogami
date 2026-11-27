import { createFileRoute } from '@tanstack/react-router'

import { parsePrintSearch, type PrintSearch } from '@/studio/print/printOptions'
import { ReleasePrintPage } from '@/studio/print/ReleasePrintPage'

// `$key_` opts out of nesting under the GameSystem page: the print view is a page of its own.
// Nothing links here yet. The options (`flow`, `paper`, `bleed`, `decks`) stay as the address
// gives them: the page checks them against the release and says what is wrong, so a value that
// is present but unknown is never replaced by a default.
export const Route = createFileRoute('/studio/game-systems/$key_/releases/$version/print')({
  staticData: { layout: 'wide' },
  validateSearch: (search: Record<string, unknown>): PrintSearch => parsePrintSearch(search),
  component: ReleasePrintRoute,
})

function ReleasePrintRoute() {
  const { key, version } = Route.useParams()
  const search = Route.useSearch()
  return <ReleasePrintPage gameSystemKey={key} version={version} search={search} />
}
