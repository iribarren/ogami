import { useState } from 'react'

import { Button } from '@/shared/ui/button'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'

import type { Campaign } from '../campaigns/useCampaigns'
import type { JournalEntry } from '../journal/useJournal'
import { pushOf } from './pushing'

type Character = Campaign['characters'][number]

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
 * for nothing. Push is possible once every select has an option; Keep leaves the roll as it is.
 */
export function PushOffer({
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
                const option = event.target.value
                setChosen((current) => {
                  const next = [...current]
                  next[index] = option
                  // A later pick of the same field holding this option loses it: it is taken now.
                  push.picks.forEach((other, after) => {
                    if (after > index && other === field && next[after] === option) {
                      next[after] = ''
                    }
                  })
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
