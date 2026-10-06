import { Link } from '@tanstack/react-router'
import { useId, type ReactNode } from 'react'

import { DiceRoller } from '../dice/DiceRoller'
import { Journal } from '../journal/Journal'
import { NoteComposer } from '../journal/NoteComposer'
import { useJournal, useRecordRoll } from '../journal/useJournal'
import { OraclePanel } from '../oracles/OraclePanel'
import { releaseLabel } from './releaseLabel'
import { SessionControls } from './SessionControls'
import { CampaignError, useCampaign, type Campaign } from './useCampaigns'

/**
 * `/play/campaigns/$campaignId`: the Play screen of a campaign. An unknown campaign, or
 * someone else's, is not found.
 */
export function CampaignPage({ campaignId }: { campaignId: string }) {
  const campaign = useCampaign(campaignId)

  return (
    <div className="space-y-6">
      <BackLink />
      <CampaignState campaign={campaign} />
    </div>
  )
}

/**
 * One state at a time: a failed refetch shows the failure instead of the data it may no
 * longer match (TanStack Query keeps the last data next to the error).
 */
function CampaignState({ campaign }: { campaign: ReturnType<typeof useCampaign> }) {
  switch (campaign.status) {
    case 'pending':
      return <p className="text-muted-foreground">Loading the campaign…</p>
    case 'error':
      return campaign.error instanceof CampaignError && campaign.error.status === 404 ? (
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
      )
    case 'success':
      return <CampaignPlay campaign={campaign.data} />
  }
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

/** Shown wherever a control needs a current scene to record in. */
const noSceneHint = 'Start a scene to write in the journal.'

/** The Play screen: where play stands, the journal, and the tools that write in it. */
function CampaignPlay({ campaign }: { campaign: Campaign }) {
  const hasScene = campaign.currentSceneNumber !== null

  return (
    <>
      <header className="space-y-1">
        <h1 className="font-heading text-3xl font-bold tracking-tight">{campaign.name}</h1>
        <p className="text-muted-foreground">
          {releaseLabel(campaign.pinnedRelease.gameSystemName, campaign.pinnedRelease.version)}
        </p>
      </header>
      <SessionControls campaign={campaign} />
      <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div className="min-w-0 space-y-6">
          <Section title="Journal">
            <CampaignJournal campaign={campaign} />
          </Section>
          <div className="space-y-2">
            {!hasScene && <p className="text-sm text-muted-foreground">{noSceneHint}</p>}
            <NoteComposer campaignId={campaign.id} disabled={!hasScene} />
          </div>
        </div>
        <div className="min-w-0 space-y-8">
          {!hasScene && <p className="text-sm text-muted-foreground">{noSceneHint}</p>}
          <Section title="Dice">
            <CampaignDice campaignId={campaign.id} disabled={!hasScene} />
          </Section>
          <Section title="Oracles">
            <OraclePanel
              campaignId={campaign.id}
              tables={campaign.oracleTables}
              likelihoodOracles={campaign.likelihoodOracles}
              disabled={!hasScene}
            />
          </Section>
        </div>
      </div>
    </>
  )
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  const headingId = useId()
  return (
    <section aria-labelledby={headingId} className="space-y-3">
      <h2 id={headingId} className="font-heading text-xl font-semibold">
        {title}
      </h2>
      {children}
    </section>
  )
}

function CampaignJournal({ campaign }: { campaign: Campaign }) {
  const journal = useJournal(campaign.id)
  switch (journal.status) {
    case 'pending':
      return <p className="text-muted-foreground">Loading the journal…</p>
    case 'error':
      return (
        <p role="alert" className="text-sm text-destructive">
          {journal.error.message}
        </p>
      )
    case 'success':
      return (
        <Journal campaignId={campaign.id} sessions={campaign.sessions} entries={journal.data} />
      )
  }
}

/** The dice roller, recording every roll in the journal. */
function CampaignDice({ campaignId, disabled }: { campaignId: string; disabled: boolean }) {
  const recordRoll = useRecordRoll(campaignId)
  return (
    <DiceRoller
      onRoll={(expression) => {
        recordRoll.mutate({ expression })
      }}
      pending={recordRoll.isPending}
      error={recordRoll.error}
      disabled={disabled}
    />
  )
}
