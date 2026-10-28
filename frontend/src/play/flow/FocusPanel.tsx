import { useId } from 'react'

import { SceneCast } from '../cast/SceneCast'
import type { Campaign } from '../campaigns/useCampaigns'
import { isAttached } from '../journal/attachments'
import { JournalEntryView } from '../journal/JournalEntryView'
import { NoteAttachments } from '../journal/NoteAttachments'
import { isListedRandomEvent } from '../journal/randomEvents'
import { RandomEventOffer } from '../journal/RandomEventOffer'
import { useJournal } from '../journal/useJournal'
import { GuidedBody } from './GuidedParts'
import { progressText, stepPercent } from './progress'
import type { FlowRun, useFlowRunCommand } from './useFlowRun'

/**
 * Focus mode, in place of the journal: only where the FlowRun stands, with a progress bar, the
 * step or scene pick to answer, the next step named and the current scene's journal entries so
 * far. The controls are the guided panel's, below the note composer.
 */
export function FocusPanel({
  campaign,
  flowRun,
  command,
}: {
  campaign: Campaign
  flowRun: FlowRun
  command: ReturnType<typeof useFlowRunCommand>
}) {
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
      <GuidedBody campaign={campaign} flowRun={flowRun} command={command} />
      <SceneSoFar campaign={campaign} sceneNumber={flowRun.progress?.sceneNumber ?? null} />
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
      characters={campaign.characters}
      sessionNumber={sessionNumber}
      scene={scene}
      editable
      sheet={campaign.pinnedRelease.sheet}
      economy={campaign.pinnedRelease}
    />
  )
}

/**
 * The journal entries of the scene the FlowRun is in: the results it has recorded there. Nothing
 * outside a scene, where the last scene's entries no longer apply.
 */
function SceneSoFar({ campaign, sceneNumber }: { campaign: Campaign; sceneNumber: number | null }) {
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
    (entry) =>
      entry.sessionNumber === campaign.currentSessionNumber &&
      entry.sceneNumber === sceneNumber &&
      !isListedRandomEvent(journal.data ?? [], entry) &&
      !isAttached(journal.data ?? [], entry),
  )
  if (sceneNumber === null || entries.length === 0) {
    return null
  }
  return (
    <section aria-labelledby={headingId} className="space-y-2">
      <h3 id={headingId} className="type-label">
        So far
      </h3>
      <ol className="space-y-3">
        {entries.map((entry) => (
          <li key={entry.id}>
            <JournalEntryView entry={entry} tags={campaign.tags}>
              <NoteAttachments
                campaignId={campaign.id}
                note={entry}
                entries={journal.data ?? []}
                tables={campaign.oracleTables}
                tags={campaign.tags}
                current
              />
            </JournalEntryView>
            <RandomEventOffer
              campaignId={campaign.id}
              entry={entry}
              entries={journal.data ?? []}
              tables={campaign.oracleTables}
              tags={campaign.tags}
              sheet={campaign.pinnedRelease.sheet}
              current
            />
          </li>
        ))}
      </ol>
    </section>
  )
}
