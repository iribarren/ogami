import { useId, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'

import type { Campaign } from '../campaigns/useCampaigns'
import {
  trackerPips,
  trackerValueText,
  useSetTrackerValue,
  visibleTrackers,
  type Tracker,
} from './useTrackers'

/**
 * The Trackers of a campaign with their values, which the player edits by hand: one row per
 * Tracker, with its level and hint when the release gives them. Nothing shows without Trackers.
 */
export function TrackersPanel({ campaign }: { campaign: Campaign }) {
  const set = useSetTrackerValue(campaign.id)
  const trackers = visibleTrackers(campaign)

  if (trackers.length === 0) {
    return null
  }
  return (
    <div className="space-y-3">
      <ul className="space-y-4">
        {trackers.map((tracker) => (
          <TrackerRow
            key={tracker.key}
            tracker={tracker}
            pending={set.isPending}
            onSet={(value) => {
              set.mutate({ key: tracker.key, value })
            }}
          />
        ))}
      </ul>
      {set.error && (
        <p role="alert" className="text-sm text-destructive">
          {set.error.message}
        </p>
      )}
    </div>
  )
}

function TrackerRow({
  tracker,
  pending,
  onSet,
}: {
  tracker: Tracker
  pending: boolean
  onSet: (value: number) => void
}) {
  const inputId = useId()
  const pips = trackerPips(tracker)
  const clamp = (value: number) => Math.min(tracker.max, Math.max(tracker.min, value))

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = event.currentTarget
    const typed = new FormData(form).get('value')
    const value = typeof typed === 'string' && typed.trim() !== '' ? Number(typed) : Number.NaN
    // A number input yields an empty string for text it cannot read: nothing to send.
    if (Number.isFinite(value)) {
      onSet(clamp(Math.round(value)))
    }
    // Back to the held value (also when the typed one clamps to it); a new value replaces it.
    form.reset()
  }

  return (
    <li className="space-y-1">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <span className="font-medium">{tracker.name}</span>
        <span className="tabular-nums">
          <span className="sr-only">Value: </span>
          {trackerValueText(tracker)}
          {tracker.levelLabel !== null && (
            <span className="ml-2 rounded-md bg-muted px-1.5 py-0.5 text-xs font-medium text-muted-foreground">
              {tracker.levelLabel}
            </span>
          )}
        </span>
      </div>
      {pips !== null && (
        <div data-slot="tracker-pips" aria-hidden="true" className="flex gap-[5px] py-1">
          {Array.from({ length: pips.total }, (_, index) =>
            index < pips.filled ? (
              <span key={index} data-filled className="pip bg-primary" />
            ) : (
              <span key={index} className="pip pip-empty" />
            ),
          )}
        </div>
      )}
      {tracker.hint !== null && <p className="text-sm text-muted-foreground">{tracker.hint}</p>}
      <form
        aria-label={`Edit ${tracker.name}`}
        className="flex items-center gap-2"
        // The value is clamped to the range instead of refused by the browser.
        noValidate
        onSubmit={handleSubmit}
      >
        <Button
          type="button"
          variant="outline"
          size="sm"
          aria-label={`Decrease ${tracker.name}`}
          disabled={pending || tracker.value <= tracker.min}
          onClick={() => {
            onSet(clamp(tracker.value - 1))
          }}
        >
          −
        </Button>
        <Label htmlFor={inputId} className="sr-only">
          {tracker.name} value
        </Label>
        <Input
          // Keyed by the value, so a new value from the server replaces what was typed.
          key={tracker.value}
          id={inputId}
          name="value"
          type="number"
          inputMode="numeric"
          min={tracker.min}
          max={tracker.max}
          step={1}
          defaultValue={tracker.value}
          disabled={pending}
          className="w-20"
        />
        <Button
          type="button"
          variant="outline"
          size="sm"
          aria-label={`Increase ${tracker.name}`}
          disabled={pending || tracker.value >= tracker.max}
          onClick={() => {
            onSet(clamp(tracker.value + 1))
          }}
        >
          +
        </Button>
        <Button type="submit" variant="ghost" size="sm" disabled={pending}>
          Set
        </Button>
      </form>
    </li>
  )
}
