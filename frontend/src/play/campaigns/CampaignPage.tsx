import { Link } from '@tanstack/react-router'
import type { ReactNode } from 'react'

import { releaseLabel } from './releaseLabel'
import { CampaignError, useCampaign, type Campaign } from './useCampaigns'

/**
 * `/play/campaigns/$campaignId`: the campaign header and where play stands. An unknown
 * campaign, or someone else's, is not found.
 */
export function CampaignPage({ campaignId }: { campaignId: string }) {
  const campaign = useCampaign(campaignId)

  return (
    <div className="space-y-6">
      <BackLink />
      {campaign.isPending && <p className="text-muted-foreground">Loading the campaign…</p>}
      {campaign.isError &&
        (campaign.error instanceof CampaignError && campaign.error.status === 404 ? (
          <section className="space-y-3">
            <h1 className="font-heading text-3xl font-bold tracking-tight">Campaign not found</h1>
            <p className="text-muted-foreground">
              This campaign does not exist, or it is not one of yours.
            </p>
          </section>
        ) : (
          <p role="alert" className="text-sm text-destructive">
            {campaign.error.message}
          </p>
        ))}
      {campaign.data && <CampaignOverview campaign={campaign.data} />}
    </div>
  )
}

function BackLink() {
  return (
    <Link
      to="/play"
      className="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
    >
      <span aria-hidden="true">← </span>Back to your campaigns
    </Link>
  )
}

function CampaignOverview({ campaign }: { campaign: Campaign }) {
  const currentSession = campaign.sessions.find(
    ({ number }) => number === campaign.currentSessionNumber,
  )
  const currentScene = currentSession?.scenes.find(
    ({ number }) => number === campaign.currentSceneNumber,
  )

  return (
    <>
      <header className="space-y-1">
        <h1 className="font-heading text-3xl font-bold tracking-tight">{campaign.name}</h1>
        <p className="text-muted-foreground">
          {releaseLabel(campaign.pinnedRelease.gameSystemName, campaign.pinnedRelease.version)}
        </p>
      </header>
      <section aria-label="Where play stands" className="max-w-md">
        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
          <Detail term="Sessions">{campaign.sessions.length}</Detail>
          <Detail term="Current session">
            {currentSession ? `Session ${String(currentSession.number)}` : 'None yet'}
          </Detail>
          <Detail term="Current scene">
            {currentScene
              ? `Scene ${String(currentScene.number)}: ${currentScene.title}`
              : 'None yet'}
          </Detail>
        </dl>
      </section>
    </>
  )
}

function Detail({ term, children }: { term: string; children: ReactNode }) {
  return (
    <>
      <dt className="text-muted-foreground">{term}</dt>
      <dd>{children}</dd>
    </>
  )
}
