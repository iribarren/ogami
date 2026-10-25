import type { Campaign } from '../campaigns/useCampaigns'
import { PushOffer } from '../checks/PushOffer'
import { JournalEntryView } from '../journal/JournalEntryView'
import { useJournal } from '../journal/useJournal'
import type { FlowStep } from './useFlowRun'

type Pending = NonNullable<FlowStep['pending']>

/**
 * The control of a `check` step whose pool roll pends (ADR 0041): the roll, as the journal
 * records it, and the push offer of the Character who rolled. The step completes on Keep or
 * Push; nothing else (no new roll, no Skip) moves the Flow.
 */
export function PendingCheckForm({
  campaign,
  stepKey,
  pending,
  busy,
  onKeep,
  onPush,
}: {
  campaign: Campaign
  stepKey: string
  pending: Pending
  /** Whether a command is under way, which disables Push and Keep. */
  busy: boolean
  onKeep: (stepKey: string) => void
  onPush: (stepKey: string, picks: string[]) => void
}) {
  const journal = useJournal(campaign.id)
  const entry = journal.data?.find(({ id }) => id === pending.entryId)

  if (entry === undefined) {
    return (
      <p role={journal.status === 'error' ? 'alert' : 'status'} className="text-sm">
        {journal.status === 'error' ? journal.error.message : 'Loading the pending roll…'}
      </p>
    )
  }
  return (
    <div className="space-y-3">
      <JournalEntryView entry={entry} sheet={campaign.pinnedRelease.sheet} />
      <PushOffer
        campaign={campaign}
        character={campaign.characters.find(({ id }) => id === pending.characterId)}
        entry={entry}
        pending={busy}
        onPush={(picks) => {
          onPush(stepKey, picks)
        }}
        onKeep={() => {
          onKeep(stepKey)
        }}
      />
    </div>
  )
}
