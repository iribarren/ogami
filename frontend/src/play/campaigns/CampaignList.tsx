import { Link } from '@tanstack/react-router'

import { releaseLabel } from './releaseLabel'
import type { CampaignSummary } from './useCampaigns'

const createdDate = new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' })

/** My campaigns, each linking to its page; an empty state when there is none. */
export function CampaignList({ campaigns }: { campaigns: CampaignSummary[] }) {
  if (campaigns.length === 0) {
    return (
      <p className="text-muted-foreground">
        You have no campaigns yet. Create one below to start playing.
      </p>
    )
  }

  return (
    <ul className="divide-y rounded-xl ring-1 ring-foreground/10">
      {campaigns.map((campaign) => (
        <li key={campaign.id} className="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-4 py-3">
          <Link
            to="/play/campaigns/$campaignId"
            params={{ campaignId: campaign.id }}
            className="font-medium underline-offset-4 hover:underline"
          >
            {campaign.name}
          </Link>
          <span className="text-sm text-muted-foreground">
            {releaseLabel(campaign.gameSystemName, campaign.releaseVersion)}
          </span>
          <span className="ml-auto text-sm text-muted-foreground">
            Created{' '}
            <time dateTime={campaign.createdAt}>
              {createdDate.format(new Date(campaign.createdAt))}
            </time>
          </span>
        </li>
      ))}
    </ul>
  )
}
