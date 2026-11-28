import { Link } from '@tanstack/react-router'

import { useCampaignTheme } from '@/shared/theme/useCampaignTheme'

import { CampaignError, useCampaign, type Campaign } from '../campaigns/useCampaigns'
import { useCampaignRelease } from '../campaigns/useCampaignRelease'
import { Mat } from './Mat'
import { matOf } from './mat'
import { TableLayout } from './TableLayout'

/**
 * `/play/campaigns/$campaignId/table`: the campaign as a table (ADR 0054). It reads the same
 * campaign query as the Play page, so both show the same FlowRun. An unknown campaign, or someone
 * else's, is not found.
 */
export function TablePage({ campaignId }: { campaignId: string }) {
  const campaign = useCampaign(campaignId)
  const release = useCampaignRelease(campaignId)

  return (
    <div className="space-y-4">
      <Link
        to="/play/campaigns/$campaignId"
        params={{ campaignId }}
        className="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
      >
        <span aria-hidden="true">← </span>Back to the Play page
      </Link>
      <TableState campaign={campaign} release={release} />
    </div>
  )
}

/** One state at a time, as on the Play page: a failed refetch shows the failure, not stale data. */
function TableState({
  campaign,
  release,
}: {
  campaign: ReturnType<typeof useCampaign>
  release: ReturnType<typeof useCampaignRelease>
}) {
  if (campaign.status === 'error') {
    return campaign.error instanceof CampaignError && campaign.error.status === 404 ? (
      <section className="space-y-3">
        <h1 className="font-heading text-page-title">Campaign not found</h1>
        <p className="text-muted-foreground">
          This campaign does not exist, or it is not one of yours.
        </p>
      </section>
    ) : (
      <p role="alert" className="text-sm text-destructive">
        {campaign.error.message}
      </p>
    )
  }
  if (release.status === 'error') {
    return (
      <p role="alert" className="text-sm text-destructive">
        {release.error.message}
      </p>
    )
  }
  if (campaign.status === 'pending' || release.status === 'pending') {
    return <p className="text-muted-foreground">Loading the table…</p>
  }
  return <Table key={campaign.data.id} campaign={campaign.data} />
}

function Table({ campaign }: { campaign: Campaign }) {
  // The whole viewport belongs to the campaign, so the release's Theme covers the header too.
  useCampaignTheme(campaign.pinnedRelease.theme)

  return (
    <>
      <h1 className="font-heading text-page-title break-words">{campaign.name}</h1>
      <TableLayout mat={<Mat mat={matOf(campaign)} />} />
    </>
  )
}
