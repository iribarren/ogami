import { useState } from 'react'

import { Button } from '@/shared/ui/button'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'
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
import { usePerformCheck } from './usePerformCheck'
import { usePushCheck } from './usePushCheck'

type Character = Campaign['characters'][number]

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

/** The pinned check of a pool entry, when it declares a push. */
function pushOf(campaign: Campaign, entry: JournalEntry) {
  const { content } = entry
  if (content.kind !== 'check') {
    return null
  }
  return campaign.checks.find(({ key }) => key === content.checkKey)?.push ?? null
}

/** Whether the entry is a pool roll that was not pushed yet, has a die that failed, and its check declares a push. */
function canPush(campaign: Campaign, entry: JournalEntry) {
  const pool = entry.content.kind === 'check' ? entry.content.pool : null
  return (
    pool !== null &&
    pool.push === null &&
    pool.successes < pool.size &&
    pushOf(campaign, entry) !== null
  )
}

/** The marked options of a conditions field in a Character's values. */
function markedOf(character: Character | undefined, field: string) {
  const value = character?.values[field]
  return Array.isArray(value)
    ? value.filter((item): item is string => typeof item === 'string')
    : []
}

/**
 * The cost of the push and one select per option the player picks (each `push.picks` field, listing
 * its options not marked yet, counting the picks above it). A field with every option marked asks
 * for nothing. Push is possible once every select has an option; Keep closes the dialog.
 */
function PushOffer({
  campaign,
  character,
  entry,
  pending,
  error,
  onPush,
  onKeep,
}: {
  campaign: Campaign
  character: Character | undefined
  entry: JournalEntry
  pending: boolean
  error?: string
  onPush: (picks: string[]) => void
  onKeep: () => void
}) {
  const push = pushOf(campaign, entry)
  const [chosen, setChosen] = useState<string[]>([])
  if (push === null) {
    return null
  }
  // What each pick can choose: the field's options not marked yet, after the picks chosen above it.
  const asks = push.picks.map((field, index) => {
    const taken = [
      ...markedOf(character, field),
      ...push.picks.flatMap((other, before) =>
        before < index && other === field && chosen[before] !== undefined ? [chosen[before]] : [],
      ),
    ]
    const options = campaign.pinnedRelease.sheet.find(({ key }) => key === field)?.options ?? []
    return { field, index, options: options.filter(({ key }) => !taken.includes(key)) }
  })
  const shown = asks.filter(({ options }) => options.length > 0)
  const picks = shown.map(({ index }) => chosen[index] ?? '')

  return (
    <div className="space-y-3">
      <p className="text-sm">
        Push: <strong>{push.label}</strong>
      </p>
      {shown.map(({ field, index, options }) => {
        const label = campaign.pinnedRelease.sheet.find(({ key }) => key === field)?.label ?? field
        return (
          <div key={index} className="space-y-1">
            <Label htmlFor={`push-pick-${String(index)}`}>{`Pick: ${label}`}</Label>
            <NativeSelect
              id={`push-pick-${String(index)}`}
              value={chosen[index] ?? ''}
              onChange={(event) => {
                setChosen((current) => {
                  const next = [...current]
                  next[index] = event.target.value
                  return next
                })
              }}
            >
              <option value="">Choose</option>
              {options.map(({ key, label: optionLabel }) => (
                <option key={key} value={key}>
                  {optionLabel}
                </option>
              ))}
            </NativeSelect>
          </div>
        )
      })}
      <div className="flex gap-2">
        <Button
          type="button"
          disabled={pending || picks.includes('')}
          onClick={() => {
            onPush(picks)
          }}
        >
          Push
        </Button>
        <Button type="button" variant="ghost" disabled={pending} onClick={onKeep}>
          Keep
        </Button>
      </div>
      {error !== undefined && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
    </div>
  )
}
