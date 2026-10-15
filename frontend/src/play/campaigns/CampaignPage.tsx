import { Link } from '@tanstack/react-router'
import { useId, type ReactNode } from 'react'

import { cn } from '@/shared/lib/utils'
import { useCampaignTheme } from '@/shared/theme/useCampaignTheme'
import { SegmentedControl, SegmentedControlItem } from '@/shared/ui/segmented-control'

import { CharactersPanel } from '../characters/CharactersPanel'
import { DiceRoller } from '../dice/DiceRoller'
import { FactsPanel } from '../facts/FactsPanel'
import { FlowIntroduction } from '../flow/FlowIntroduction'
import { FocusPanel } from '../flow/FocusPanel'
import { GuidedControls } from '../flow/GuidedParts'
import { GuidedPanel } from '../flow/GuidedPanel'
import { useFlowRunCommand } from '../flow/useFlowRun'
import { useFlowView, type FlowView } from '../flow/useFlowView'
import { Journal } from '../journal/Journal'
import { NoteComposer } from '../journal/NoteComposer'
import { useJournal, useRecordRoll } from '../journal/useJournal'
import { ListPanel } from '../lists/ListPanel'
import { OraclePanel } from '../oracles/OraclePanel'
import { TrackersPanel } from '../trackers/TrackersPanel'
import { visibleTrackers } from '../trackers/useTrackers'
import { CreditsNotice } from './CreditsNotice'
import { PlayPanel } from './PlayPanel'
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
    case 'success':
      return <CampaignPlay key={campaign.data.id} campaign={campaign.data} />
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
  // The whole viewport belongs to the campaign, so the release's Theme covers the header too.
  useCampaignTheme(campaign.pinnedRelease.theme)
  const hasScene = campaign.currentSceneNumber !== null
  const scene = campaign.sessions
    .find(({ number }) => number === campaign.currentSessionNumber)
    ?.scenes.find(({ number }) => number === campaign.currentSceneNumber)
  const flow = campaign.flows.find(({ key }) => key === campaign.flowKey)
  const [view, setView] = useFlowView(campaign)
  const trackers = visibleTrackers(campaign)
  const focused = campaign.flowRun !== null && view === 'focus'
  // Shared by the step card and the controls below the composer, so one pending state covers both.
  const command = useFlowRunCommand(campaign.id)

  return (
    <>
      <header className="space-y-1">
        <h1 className="font-heading text-page-title break-words">{campaign.name}</h1>
        <p className="text-muted-foreground">
          {releaseLabel(campaign.pinnedRelease.gameSystemName, campaign.pinnedRelease.version)}
        </p>
        <CreditsNotice credits={campaign.pinnedRelease.credits} />
      </header>
      <FlowIntroduction campaign={campaign} />
      <SessionControls campaign={campaign} />
      <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_var(--play-sidebar-width)]">
        <div
          className={cn(
            'min-w-0 space-y-6',
            // Focus mode centres the Step card; the Journal keeps a reading measure.
            focused ? 'mx-auto w-full max-w-2xl' : 'max-w-prose leading-relaxed',
          )}
        >
          {campaign.flowRun !== null && <ViewToggle view={view} onChange={setView} />}
          {campaign.flowRun !== null && focused ? (
            <FocusPanel campaign={campaign} flowRun={campaign.flowRun} command={command} />
          ) : (
            <Section title="Journal">
              <CampaignJournal campaign={campaign} />
              {campaign.flowRun !== null && (
                <GuidedPanel campaign={campaign} flowRun={campaign.flowRun} command={command} />
              )}
            </Section>
          )}
          <div className="space-y-2">
            {!hasScene && <p className="text-sm text-muted-foreground">{noSceneHint}</p>}
            <NoteComposer campaignId={campaign.id} disabled={!hasScene} />
          </div>
          {campaign.flowRun !== null && (
            <GuidedControls campaign={campaign} flowRun={campaign.flowRun} command={command} />
          )}
        </div>
        <aside
          aria-label="Play tools"
          className="min-w-0 space-y-4 lg:sticky lg:top-4 lg:-mx-1 lg:max-h-[calc(100dvh-2rem)] lg:self-start lg:overflow-y-auto lg:px-1 lg:pb-2"
        >
          {!hasScene && <p className="text-sm text-muted-foreground">{noSceneHint}</p>}
          <PlayPanel id="dice" title="Dice">
            <CampaignDice campaignId={campaign.id} disabled={!hasScene} />
          </PlayPanel>
          {trackers.length > 0 && (
            <PlayPanel
              id="trackers"
              title="Trackers"
              count={trackers.length}
              help="Counters and clocks the flow reads to decide what happens next."
            >
              <TrackersPanel campaign={campaign} />
            </PlayPanel>
          )}
          <PlayPanel
            id="facts"
            title="Facts"
            count={campaign.facts.length}
            help="What the story has settled. The flow asks for some; refill one when play changes it."
          >
            <FactsPanel campaign={campaign} />
          </PlayPanel>
          <PlayPanel
            id="threads"
            title="Threads"
            count={campaign.threads.length}
            help="The open questions and goals your story follows. Add one when something needs resolving, such as a mystery, a promise or a rival, and close it when it is settled."
          >
            <ListPanel campaign={campaign} kind="thread" />
          </PlayPanel>
          <PlayPanel
            id="npcs"
            title="NPCs"
            count={campaign.npcs.length}
            help="The people met in play. Add them as they appear; the flow adds some too."
          >
            <ListPanel campaign={campaign} kind="npc" />
          </PlayPanel>
          <PlayPanel
            id="characters"
            title="Characters"
            count={campaign.characters.length}
            help="Your characters and their sheets. Open one to read or edit it."
          >
            <CharactersPanel campaign={campaign} />
          </PlayPanel>
          <PlayPanel
            id="oracles"
            title="Oracles"
            help="Ask a yes/no question or roll a table when you need ideas. The result is yours to interpret."
          >
            <OraclePanel
              campaignId={campaign.id}
              tables={campaign.oracleTables}
              likelihoodOracles={campaign.likelihoodOracles}
              sceneOracleKeys={
                campaign.sceneTypes.find(({ key }) => key === scene?.sceneType)?.oracles
              }
              flow={
                flow === undefined || campaign.flowRun?.status !== 'active'
                  ? null
                  : { name: flow.name, oracleKeys: flow.oracles }
              }
              disabled={!hasScene}
            />
          </PlayPanel>
        </aside>
      </div>
    </>
  )
}

/** Journal view or focus mode, for a campaign a Flow guides. */
function ViewToggle({ view, onChange }: { view: FlowView; onChange: (view: FlowView) => void }) {
  return (
    <SegmentedControl
      aria-label="Guided view"
      value={view}
      onValueChange={(next) => {
        if (next === 'journal' || next === 'focus') {
          onChange(next)
        }
      }}
    >
      <SegmentedControlItem value="journal">Journal view</SegmentedControlItem>
      <SegmentedControlItem value="focus">Focus view</SegmentedControlItem>
    </SegmentedControl>
  )
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  const headingId = useId()
  return (
    <section aria-labelledby={headingId} className="space-y-3">
      <h2 id={headingId} className="font-heading text-section-title">
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
        <Journal
          campaignId={campaign.id}
          sessions={campaign.sessions}
          sceneTypes={campaign.sceneTypes}
          tags={campaign.tags}
          sheet={campaign.pinnedRelease.sheet}
          npcs={campaign.npcs}
          characters={campaign.characters}
          oracleTables={campaign.oracleTables}
          current={
            campaign.currentSessionNumber === null || campaign.currentSceneNumber === null
              ? null
              : { session: campaign.currentSessionNumber, scene: campaign.currentSceneNumber }
          }
          entries={journal.data}
        />
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
