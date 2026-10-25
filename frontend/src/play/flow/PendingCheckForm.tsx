import type { Campaign } from '../campaigns/useCampaigns'
import { Button } from '@/shared/ui/button'

import { PushOffer } from '../checks/PushOffer'
import { canPush } from '../checks/pushing'
import { JournalEntryView } from '../journal/JournalEntryView'
import { useJournal } from '../journal/useJournal'
import type { FlowStep } from './useFlowRun'

type Pending = NonNullable<FlowStep['pending']>

/**
 * The control of a `check` step whose pool roll pends (ADR 0041): the roll, as the journal
 * records it, and the push offer of the Character who rolled. The step completes on Keep or
 * Push; Keep is always offered, also when the journal does not hold the roll or the roll has no
 * push to offer, so the step never dead-ends. Nothing else (no new roll, no Skip) moves the Flow.
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

  const keep = (
    <Button
      type="button"
      variant="ghost"
      disabled={busy}
      onClick={() => {
        onKeep(stepKey)
      }}
    >
      Keep
    </Button>
  )

  if (journal.status === 'pending') {
    return (
      <p role="status" className="text-sm">
        Loading the pending roll…
      </p>
    )
  }
  if (entry === undefined) {
    return (
      <div className="space-y-3">
        <p role={journal.status === 'error' ? 'alert' : 'status'} className="text-sm">
          {journal.status === 'error'
            ? journal.error.message
            : 'The pending roll is not in the journal.'}
        </p>
        <div>{keep}</div>
      </div>
    )
  }
  return (
    <div className="space-y-3">
      <JournalEntryView entry={entry} sheet={campaign.pinnedRelease.sheet} />
      {!canPush(campaign, entry) ? (
        <div>{keep}</div>
      ) : (
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
      )}
    </div>
  )
}
