import { useState } from 'react'

import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/shared/ui/dialog'

import type { Campaign } from '../campaigns/useCampaigns'
import { JournalEntryView } from '../journal/JournalEntryView'
import type { JournalEntry } from '../journal/useJournal'
import { CheckForm } from './CheckForm'
import { PushOffer } from './PushOffer'
import { canPush } from './pushing'
import { usePerformCheck } from './usePerformCheck'
import { usePushCheck } from './usePushCheck'

/**
 * "Roll a check" for one Character of the sheet: a dialog with the check form. The result is
 * recorded in the journal, and the dialog closes on success; a refusal stays in the form. The roll
 * of a pool check that can be pushed, with a die that failed, keeps the dialog open on the result,
 * which offers Push (with its cost and the options the player picks) or Keep (ADR 0041).
 */
export function RollCheckDialog({
  campaign,
  character,
}: {
  campaign: Campaign
  character: { id: string; name: string }
}) {
  const [open, setOpen] = useState(false)
  const perform = usePerformCheck(campaign.id)
  const push = usePushCheck(campaign.id)
  // The entry the dialog shows the result of, while there is something to decide or read.
  const [result, setResult] = useState<JournalEntry | null>(null)
  const [pushed, setPushed] = useState(false)

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        perform.reset()
        push.reset()
        setResult(null)
        setPushed(false)
        setOpen(next)
      }}
    >
      <DialogTrigger asChild>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          aria-label={`Roll a check for ${character.name}`}
        >
          Roll a check
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Roll a check for {character.name}</DialogTitle>
          <DialogDescription>
            The result is recorded in the journal, in the current scene.
          </DialogDescription>
        </DialogHeader>
        {result !== null ? (
          <div className="space-y-3">
            <JournalEntryView entry={result} sheet={campaign.pinnedRelease.sheet} />
            {pushed || !canPush(campaign, result) ? (
              <Button
                type="button"
                onClick={() => {
                  setOpen(false)
                }}
              >
                Done
              </Button>
            ) : (
              <PushOffer
                campaign={campaign}
                character={campaign.characters.find(({ id }) => id === character.id)}
                entry={result}
                pending={push.isPending}
                error={push.error?.message}
                onPush={(picks) => {
                  push.mutate(
                    { entryId: result.id, picks },
                    {
                      onSuccess: (entry) => {
                        setResult(entry)
                        setPushed(true)
                      },
                    },
                  )
                }}
                onKeep={() => {
                  setOpen(false)
                }}
              />
            )}
          </div>
        ) : (
          <CheckForm
            checks={campaign.checks}
            pending={perform.isPending}
            error={perform.error?.message}
            onSubmit={({ check, difficulty, situational }) => {
              perform.mutate(
                { check, character: character.id, difficulty, situational },
                {
                  onSuccess: (entry) => {
                    if (canPush(campaign, entry)) {
                      setResult(entry)
                    } else {
                      setOpen(false)
                    }
                  },
                },
              )
            }}
          />
        )}
      </DialogContent>
    </Dialog>
  )
}
