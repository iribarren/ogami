import { Button } from '@/shared/ui/button'

import { NextPicker } from './BandsEditor'
import { EffectsEditor } from './EffectsEditor'
import type { StepContext } from './stepContext'
import type { OutcomeDraft } from './stepDraft'
import { aboutEffects, type StepError } from './stepError'

/**
 * What follows an oracle answer, a table entry, `otherwise`, a choice option, a pick outcome or a
 * check band: a `next` and effects, sheet effects only where `allowSheet` (a check outcome: the
 * Character is known there). `errors` are the server's messages
 * about this outcome, `nextGuide` the client's note on a backward `next`. `onRestructure` tells the
 * owner of the messages that effects moved or went.
 */
export function OutcomeEditor({
  label,
  outcome,
  context,
  laterKeys,
  nextGuide,
  errors,
  allowSheet = false,
  onChange,
  onRestructure,
}: {
  label: string
  outcome: OutcomeDraft
  context: StepContext
  laterKeys: string[]
  nextGuide?: string
  errors: StepError[]
  allowSheet?: boolean
  onChange: (outcome: OutcomeDraft) => void
  onRestructure?: () => void
}) {
  const nextError = errors.find((error) => error.field === 'next' && error.effect === null)
  // Anything else the server said about the outcome itself is listed, never lost.
  const loose = errors.filter((error) => !aboutEffects(error) && error !== nextError)

  return (
    <div
      role="group"
      aria-label={label}
      className="space-y-2 rounded-lg p-3 ring-1 ring-foreground/10"
    >
      <p className="text-sm font-medium">{label}</p>
      <NextPicker
        label="Next"
        value={outcome.next}
        laterKeys={laterKeys}
        error={nextError?.message ?? nextGuide}
        onChange={(next) => {
          onChange({ ...outcome, next })
        }}
      />
      <EffectsEditor
        label={`${label} effects`}
        effects={outcome.effects}
        context={context}
        allowSheet={allowSheet}
        errors={errors.filter(aboutEffects)}
        onChange={(effects) => {
          onChange({ ...outcome, effects })
        }}
        onRestructure={onRestructure}
      />
      {loose.map((error, at) => (
        <p key={at} role="alert" className="text-sm text-destructive">
          {error.field === null ? error.message : `${error.field}: ${error.message}`}
        </p>
      ))}
    </div>
  )
}

/** Up, down and delete of one row of a list (`noun` is `branch` or `option`); delete can be held back (the list says why). */
export function RowControls({
  noun,
  index,
  total,
  canDelete = true,
  onMove,
  onDelete,
}: {
  noun: string
  index: number
  total: number
  canDelete?: boolean
  onMove: (by: number) => void
  onDelete: () => void
}) {
  const number = String(index + 1)

  return (
    <div className="flex flex-wrap gap-2">
      <Button
        type="button"
        variant="outline"
        size="sm"
        disabled={index === 0}
        aria-label={`Move ${noun} ${number} up`}
        onClick={() => {
          onMove(-1)
        }}
      >
        Up
      </Button>
      <Button
        type="button"
        variant="outline"
        size="sm"
        disabled={index === total - 1}
        aria-label={`Move ${noun} ${number} down`}
        onClick={() => {
          onMove(1)
        }}
      >
        Down
      </Button>
      <Button
        type="button"
        variant="outline"
        size="sm"
        disabled={!canDelete}
        aria-label={`Delete ${noun} ${number}`}
        onClick={onDelete}
      >
        Delete
      </Button>
    </div>
  )
}
