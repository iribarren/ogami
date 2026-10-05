import { useId, useState, type SubmitEvent } from 'react'

import { cn } from '@/shared/lib/utils'
import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'

import { useRollDice, type DiceGroup, type Roll } from './useRollDice'

const defaultPresets = ['d20', '2d6', '4d6kh3', 'd%'] as const

export interface DiceRollerProps {
  /** The dice expression the input starts with; empty by default. */
  initialExpression?: string
  /** Dice expressions offered as one-click rolls; pass `[]` to hide them. */
  presets?: readonly string[]
  /** Called with every successful roll, e.g. to log it somewhere else. */
  onRolled?: (roll: Roll) => void
}

/** Rolls a dice expression through the API and shows the total and every die rolled. */
export function DiceRoller({
  initialExpression = '',
  presets = defaultPresets,
  onRolled,
}: DiceRollerProps) {
  const [expression, setExpression] = useState(initialExpression)
  const rollDice = useRollDice()
  const inputId = useId()
  const totalLabelId = useId()

  function roll(notation: string) {
    rollDice.mutate({ expression: notation }, { onSuccess: (result) => onRolled?.(result) })
  }

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    if (expression.trim() !== '') {
      roll(expression)
    }
  }

  function rollPreset(preset: string) {
    setExpression(preset)
    roll(preset)
  }

  return (
    <div className="space-y-4">
      <form className="space-y-2" onSubmit={handleSubmit}>
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
            required
          />
          <Button type="submit" disabled={rollDice.isPending}>
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
              disabled={rollDice.isPending}
              onClick={() => {
                rollPreset(preset)
              }}
            >
              {preset}
            </Button>
          ))}
        </div>
      )}

      {rollDice.error && (
        <p role="alert" className="text-sm text-destructive">
          {rollDice.error.message}
        </p>
      )}

      {/* Always rendered so screen readers announce each new result. */}
      <section aria-label="Roll result" aria-live="polite" aria-atomic="true">
        {rollDice.data && (
          <div className="space-y-3 rounded-xl p-4 ring-1 ring-foreground/10">
            <p className="font-mono text-sm text-muted-foreground">{rollDice.data.expression}</p>
            <p className="flex items-baseline gap-2">
              <span id={totalLabelId} className="text-sm text-muted-foreground">
                Total
              </span>
              <span aria-describedby={totalLabelId} className="text-4xl font-bold tabular-nums">
                {rollDice.data.total}
              </span>
            </p>
            {rollDice.data.groups.length > 0 && (
              <ul className="space-y-2" aria-label="Dice groups">
                {rollDice.data.groups.map((group, index) => (
                  <DiceGroupRow key={index} group={group} />
                ))}
              </ul>
            )}
          </div>
        )}
      </section>
    </div>
  )
}

function DiceGroupRow({ group }: { group: DiceGroup }) {
  return (
    <li className="flex flex-wrap items-center gap-2 text-sm">
      <span className="font-mono">{group.notation}</span>
      <ul className="flex flex-wrap gap-1" aria-label={`${group.notation} dice`}>
        {group.dice.map((die, index) => (
          <li
            key={index}
            className={cn(
              'min-w-7 rounded-md px-1.5 py-0.5 text-center tabular-nums ring-1',
              die.kept
                ? 'font-semibold ring-foreground/20'
                : 'text-muted-foreground line-through ring-foreground/10',
            )}
          >
            {die.value}
            {!die.kept && <span className="sr-only"> (dropped)</span>}
          </li>
        ))}
      </ul>
      <span className="text-muted-foreground">= {group.subtotal}</span>
    </li>
  )
}
