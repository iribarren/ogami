import { useId } from 'react'

import { Label } from '@/shared/ui/label'

import { TextField } from '../narrative/TextField'
import type { EffectDraft } from '../narrative/steps/effectDraft'
import { EffectsEditor } from '../narrative/steps/EffectsEditor'
import type { StepContext } from '../narrative/steps/stepContext'
import type { StepError } from '../narrative/steps/stepError'
import type { PushDraft } from './checkDraft'

/** Sheet and tracker effects only: what the cost of a push may change. */
const costKinds = ['sheet', 'tracker'] as const

/**
 * The push of a pool check: a toggle, the cost in words and the effects of the cost, edited with
 * the shared effects editor. A mark may leave the option to the player here and nowhere else.
 */
export function PushEditor({
  push,
  context,
  labelError,
  maxDiceError,
  effectErrors,
  onChange,
  onRestructure,
}: {
  push: PushDraft | null
  context: StepContext
  labelError?: string
  maxDiceError?: string
  effectErrors: StepError[]
  onChange: (push: PushDraft | null) => void
  onRestructure: () => void
}) {
  const toggleId = useId()
  return (
    <fieldset className="space-y-3">
      <legend className="text-sm font-medium">Push</legend>
      <div className="flex items-center gap-2">
        <input
          id={toggleId}
          type="checkbox"
          checked={push !== null}
          onChange={(event) => {
            onChange(event.target.checked ? { label: '', effects: [] } : null)
          }}
        />
        <Label htmlFor={toggleId}>Can be pushed</Label>
      </div>
      <p className="hint">
        A player may push a roll with a failed die once: the failed dice are re-rolled, then the
        cost applies.
      </p>
      {push !== null && (
        <div className="space-y-3">
          <TextField
            label="Cost label"
            value={push.label}
            error={labelError}
            help="What the push costs, in the player's words, such as Take a Condition."
            onChange={(label) => {
              onChange({ ...push, label })
            }}
          />
          <TextField
            label="Dice cap"
            value={push.maxDice ?? ''}
            error={maxDiceError}
            help="Optional, 1 to 100: the most failed dice a push re-rolls, such as 3. Empty re-rolls every failed die. Hunger dice are never re-rolled."
            onChange={(maxDice) => {
              onChange({ ...push, maxDice })
            }}
          />
          <EffectsEditor
            label="Cost effects"
            effects={push.effects}
            context={context}
            allowSheet
            kinds={costKinds}
            allowPick
            errors={effectErrors}
            onChange={(effects: EffectDraft[]) => {
              onChange({ ...push, effects })
            }}
            onRestructure={onRestructure}
          />
        </div>
      )}
    </fieldset>
  )
}
