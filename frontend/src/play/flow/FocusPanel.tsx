import { useId } from 'react'

import { SceneCast } from '../cast/SceneCast'
import type { Campaign } from '../campaigns/useCampaigns'
import { JournalEntryView } from '../journal/JournalEntryView'
import { RandomEventOffer } from '../journal/RandomEventOffer'
import { useJournal } from '../journal/useJournal'
import { GuidedBody, GuidedControls } from './GuidedParts'
import { progressText, stepPercent } from './progress'
import { useFlowRunCommand, type FlowRun, type FlowRunCommand } from './useFlowRun'

/**
 * Focus mode, in place of the journal: only where the FlowRun stands, with a progress bar, the
 * step or scene pick to answer, the next step named, the same controls as the guided panel, and
 * the current scene's journal entries so far.
 */
export function FocusPanel({ campaign, flowRun }: { campaign: Campaign; flowRun: FlowRun }) {
  const command = useFlowRunCommand(campaign.id)
  const parts = {
    campaign,
    flowRun,
    command,
    onCommand: (next: FlowRunCommand) => {
      command.mutate(next)
    },
  }
  const percent = flowRun.progress === null ? null : stepPercent(flowRun.progress)

  return (
    <section aria-label="Focus mode" className="space-y-4 rounded-xl p-4 ring-1 ring-foreground/10">
      {flowRun.progress !== null && (
        <div className="space-y-2">
          <p aria-label="Progress" className="text-sm font-medium">
            {progressText(flowRun.progress)}
          </p>
          {percent !== null && (
            <div
              role="progressbar"
              aria-label={`Step ${String(flowRun.progress.stepNumber)} of ${String(flowRun.progress.stepCount)}`}
              aria-valuemin={0}
              aria-valuemax={100}
              aria-valuenow={percent}
              className="h-1.5 overflow-hidden rounded-full bg-muted"
            >
              <div
                className="h-full rounded-full bg-primary"
                style={{ width: `${String(percent)}%` }}
              />
            </div>
          )}
        </div>
      )}
      <CurrentCast campaign={campaign} />
      <GuidedBody {...parts} />
      <GuidedControls {...parts} />
      <SceneSoFar campaign={campaign} />
    </section>
  )
}

/** The cast of the current scene, which the player can edit; nothing before a scene starts. */
function CurrentCast({ campaign }: { campaign: Campaign }) {
  const sessionNumber = campaign.currentSessionNumber
  const scene = campaign.sessions
    .find(({ number }) => number === sessionNumber)
    ?.scenes.find(({ number }) => number === campaign.currentSceneNumber)
  if (sessionNumber === null || scene === undefined) {
    return null
  }
  return (
    <SceneCast
      campaignId={campaign.id}
      npcs={campaign.npcs}
      sessionNumber={sessionNumber}
      scene={scene}
      editable
    />
  )
}

/** The journal entries of the current scene: the results the FlowRun has recorded there. */
function SceneSoFar({ campaign }: { campaign: Campaign }) {
  const journal = useJournal(campaign.id)
  const headingId = useId()
  if (journal.status === 'error') {
    return (
      <p role="alert" className="text-sm text-destructive">
        {journal.error.message}
      </p>
    )
  }
  const entries = (journal.data ?? []).filter(
    ({ sessionNumber, sceneNumber }) =>
      sessionNumber === campaign.currentSessionNumber &&
      sceneNumber === campaign.currentSceneNumber,
  )
  if (entries.length === 0) {
    return null
  }
  return (
    <section aria-labelledby={headingId} className="space-y-2">
      <h3
        id={headingId}
        className="text-sm font-semibold tracking-wide text-muted-foreground uppercase"
      >
        So far
      </h3>
      <ol className="space-y-3">
        {entries.map((entry) => (
          <li key={entry.id}>
            <JournalEntryView entry={entry} tags={campaign.tags} />
            <RandomEventOffer
              campaignId={campaign.id}
              entry={entry}
              tables={campaign.oracleTables}
            />
          </li>
        ))}
      </ol>
    </section>
  )
}
