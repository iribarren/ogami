import { useState } from 'react'

import { Button } from '@/shared/ui/button'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'

import type { Campaign } from '../campaigns/useCampaigns'
import type { JournalEntry } from '../journal/useJournal'
import { burnScore, diceBeatenBy, hasHungerDice, pushOf } from './pushing'

type Character = Campaign['characters'][number]

/** The marked options of a conditions field in a Character's values. */
function markedOf(character: Character | undefined, field: string) {
  const value = character?.values[field]
  return Array.isArray(value)
    ? value.filter((item): item is string => typeof item === 'string')
    : []
}

type Outlook =
  | { kind: 'unreadable'; label: string }
  | {
      kind: 'improves' | 'no better'
      label: string
      momentum: number
      score: number
      capped: number | null
      beats: number
      rolled: number
      total: number
    }

/**
 * What burning the momentum now would do to a challenge roll: the momentum on the sheet (the check
 * names its field), the score it gives and the dice it beats against the roll. A value that is not
 * a number, or no Character, is unreadable: it never counts as 0.
 */
function burnOutlook(
  campaign: Campaign,
  entry: JournalEntry,
  character: Character | undefined,
): Outlook | null {
  const { content } = entry
  if (content.kind !== 'check' || content.challenge === null) {
    return null
  }
  const { challenge } = content
  const named = campaign.checks.find(({ key }) => key === content.checkKey)?.challenge
  const label = named?.momentumLabel ?? 'Momentum'
  const value = named?.momentum == null ? undefined : character?.values[named.momentum]
  if (typeof value !== 'number' || !Number.isFinite(value)) {
    return { kind: 'unreadable', label }
  }
  const score = burnScore(value, challenge.scoreMax)
  const beats = diceBeatenBy(challenge.dice, score)
  const rolled = diceBeatenBy(challenge.dice, challenge.score)
  return {
    kind: beats > rolled ? 'improves' : 'no better',
    label,
    momentum: value,
    score,
    capped: challenge.scoreMax !== null && value > challenge.scoreMax ? challenge.scoreMax : null,
    beats,
    rolled,
    total: challenge.dice.length,
  }
}

function BurnOutlook({ outlook }: { outlook: Outlook }) {
  if (outlook.kind === 'unreadable') {
    return (
      <p className="text-sm">
        {outlook.label} cannot be read from the sheet of the Character, so it cannot be burned.
      </p>
    )
  }
  const counts = `${String(outlook.beats)} of ${String(outlook.total)} challenge dice, against ${String(outlook.rolled)} with the roll`
  return (
    <div className="space-y-1 text-sm">
      <p>
        {outlook.label} now: <strong>{outlook.momentum}</strong>
        {outlook.kind === 'improves' && (
          <>
            {' · '}score <strong>{outlook.score}</strong>
            {outlook.capped !== null && ` (capped at ${String(outlook.capped)})`}
          </>
        )}
      </p>
      <p className={outlook.kind === 'improves' ? undefined : 'text-muted-foreground'}>
        {outlook.kind === 'improves'
          ? `It would beat ${counts}.`
          : `It would beat only ${counts}: burning it would not help.`}
      </p>
    </div>
  )
}

/**
 * The cost of the push and one select per option the player picks (each `push.picks` field, listing
 * its options not marked yet, counting the picks above it). A field with every option marked asks
 * for nothing. Push is possible once every select has an option; Keep leaves the roll as it is.
 * It tells the cap of the push, and that Hunger dice are never re-rolled (ADR 0044). On every pool
 * roll it says what the push can still change (more successes, never fewer) and what it risks (the
 * cost, whatever the dice show, and a messy critical with Hunger dice), from ADR 0041 and ADR 0044.
 * A band check has no difficulty, so the offer cannot tell a success and the copy holds either way.
 *
 * The push of a challenge roll is a burn (ADR 0049): no dice are re-rolled. It tells the momentum on
 * the Character's sheet now, the score it would give and the dice it would beat, against those of
 * the roll. When the momentum now beats no more dice, or cannot be read, only Keep stays, with a
 * line saying why: the sheet may have changed since the roll, and the API checks it again.
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
  const pool = entry.content.kind === 'check' ? entry.content.pool : null
  const challenge = entry.content.kind === 'check' ? entry.content.challenge : null
  const burn = challenge === null ? null : burnOutlook(campaign, entry, character)
  const rerolls =
    push.maxDice === null
      ? 'Re-roll every failed regular die.'
      : `Re-roll up to ${String(push.maxDice)} failed ${push.maxDice === 1 ? 'die' : 'dice'}.`

  return (
    <div className="space-y-3">
      <p className="text-sm">
        {challenge === null ? 'Push' : 'Burn'}: <strong>{push.label}</strong>
      </p>
      {burn !== null && <BurnOutlook outlook={burn} />}
      {pool !== null && (
        <p className="text-sm">
          A push re-rolls only the dice that failed and keeps the successes, so it cannot lower the
          result; it can only add successes, which may reach a higher band or a critical. The cost
          is paid whatever the dice show, and a roll is pushed once. Keep takes the result as it is,
          at no cost.
          {hasHungerDice(pool) &&
            ' A new critical that pairs with a Hunger die is a messy critical.'}
        </p>
      )}
      {(push.maxDice !== null || (pool !== null && hasHungerDice(pool))) && (
        <p className="text-sm text-muted-foreground">
          <span>{rerolls}</span>
          {pool !== null && hasHungerDice(pool) && <span> Hunger dice are never re-rolled.</span>}
        </p>
      )}
      {(burn === null || burn.kind === 'improves') &&
        shown.map(({ field, index, options }) => {
          const label =
            campaign.pinnedRelease.sheet.find(({ key }) => key === field)?.label ?? field
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
        {(burn === null || burn.kind === 'improves') && (
          <Button
            type="button"
            disabled={pending || picks.includes('')}
            onClick={() => {
              onPush(picks)
            }}
          >
            {challenge === null ? 'Push' : 'Burn'}
          </Button>
        )}
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
