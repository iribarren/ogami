import { createFileRoute } from '@tanstack/react-router'

import { TablePage } from '@/play/table/TablePage'

// `$campaignId_` opts out of nesting under the Play page: the table is a page of its own.
export const Route = createFileRoute('/play/campaigns/$campaignId_/table')({
  staticData: { layout: 'wide' },
  component: TableRoute,
})

function TableRoute() {
  const { campaignId } = Route.useParams()
  return <TablePage campaignId={campaignId} />
}
