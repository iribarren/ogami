import { createFileRoute } from '@tanstack/react-router'

import { CampaignPage } from '@/play/campaigns/CampaignPage'

export const Route = createFileRoute('/play/campaigns/$campaignId')({
  component: CampaignRoute,
})

function CampaignRoute() {
  const { campaignId } = Route.useParams()
  return <CampaignPage campaignId={campaignId} />
}
