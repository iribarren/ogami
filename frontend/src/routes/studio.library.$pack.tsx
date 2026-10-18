import { createFileRoute } from '@tanstack/react-router'

import { LibraryPackPage } from '@/studio/library/LibraryPackPage'

export const Route = createFileRoute('/studio/library/$pack')({
  component: LibraryPackRoute,
})

function LibraryPackRoute() {
  const { pack } = Route.useParams()
  return <LibraryPackPage pack={pack} />
}
