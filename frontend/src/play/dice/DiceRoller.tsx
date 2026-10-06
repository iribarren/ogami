import { useId, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'

const defaultPresets = ['d20', '2d6', '4d6kh3', 'd%'] as const

export interface DiceRollerProps {
  /** Rolls a dice expression; the caller decides where it is rolled and where the result shows. */
  onRoll: (expression: string) => void
  /** True while a roll is in flight: rolling again is disabled. */
  pending?: boolean
  /** Why the last roll was refused, shown under the form. */
  error?: Error | null
  /** Disables every control, e.g. while there is nowhere to record a roll. */
  disabled?: boolean
  /** The dice expression the input starts with; empty by default. */
  initialExpression?: string
  /** Dice expressions offered as one-click rolls; pass `[]` to hide them. */
  presets?: readonly string[]
}

/** A dice expression form with one-click presets; the result is shown by the caller. */
export function DiceRoller({
  onRoll,
  pending = false,
  error = null,
  disabled = false,
  initialExpression = '',
  presets = defaultPresets,
}: DiceRollerProps) {
  const [expression, setExpression] = useState(initialExpression)
  const inputId = useId()
  const blocked = disabled || pending

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    if (expression.trim() !== '' && !blocked) {
      onRoll(expression)
    }
  }

  function rollPreset(preset: string) {
    setExpression(preset)
    onRoll(preset)
  }

  return (
    <div className="space-y-3">
      <form aria-label="Roll dice" className="space-y-2" onSubmit={handleSubmit}>
        <Label htmlFor={inputId}>Dice expression</Label>
        <div className="flex gap-2">
          <Input
            id={inputId}
            name="expression"
            value={expression}
            onChange={(event) => {
              setExpression(event.target.value)
            }}
            placeholder="2d6+1"
            autoComplete="off"
            spellCheck={false}
            disabled={disabled}
            required
          />
          <Button type="submit" disabled={blocked}>
            Roll
          </Button>
        </div>
      </form>

      {presets.length > 0 && (
        <div className="flex flex-wrap gap-2" role="group" aria-label="Quick rolls">
          {presets.map((preset) => (
            <Button
              key={preset}
              type="button"
              variant="outline"
              size="sm"
              aria-label={`Roll ${preset}`}
              disabled={blocked}
              onClick={() => {
                rollPreset(preset)
              }}
            >
              {preset}
            </Button>
          ))}
        </div>
      )}

      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error.message}
        </p>
      )}
    </div>
  )
}
