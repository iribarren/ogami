import { cn } from '@/shared/lib/utils'

/** One dice group of a roll: its notation, every die rolled (dropped ones too) and its subtotal. */
export interface DiceGroup {
  notation: string
  dice: { value: number; kept: boolean }[]
  subtotal: number
}

/** The dice groups of a roll; dropped dice are struck through and announced as dropped. */
export function DiceGroups({ groups }: { groups: DiceGroup[] }) {
  if (groups.length === 0) {
    return null
  }
  return (
    <ul className="space-y-1" aria-label="Dice groups">
      {groups.map((group, index) => (
        <li key={index} className="flex flex-wrap items-center gap-2 text-sm">
          <span className="font-mono">{group.notation}</span>
          <ul className="flex flex-wrap gap-1" aria-label={`${group.notation} dice`}>
            {group.dice.map((die, dieIndex) => (
              <li
                key={dieIndex}
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
          <span className="text-muted-foreground tabular-nums">= {group.subtotal}</span>
        </li>
      ))}
    </ul>
  )
}
