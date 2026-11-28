import { cn } from '@/shared/lib/utils'
import { DieShape, type DieSides } from '@/shared/ui/die-shape'

import type { LatestRoll } from './latestRoll'
import './tableMotion.css'

const shapes: readonly number[] = [4, 6, 8, 10, 12, 20]
const hasShape = (sides: number): sides is DieSides => shapes.includes(sides)

/**
 * One die at rest on its result. A die the app has no shape for (a d100, a d3) is a numbered chip.
 * A dropped die is dimmed and announced as dropped.
 */
function RolledDie({ sides, value, kept }: { sides: number; value: number; kept: boolean }) {
  const face = (
    <>
      <span className="relative font-semibold tabular-nums">{value}</span>
      {!kept && <span className="sr-only"> (dropped)</span>}
    </>
  )
  return hasShape(sides) ? (
    <span
      data-die={sides}
      className={cn(
        'relative inline-flex size-11 items-center justify-center',
        !kept && 'opacity-40',
      )}
    >
      <DieShape sides={sides} className="absolute inset-0 size-11" />
      {face}
    </span>
  ) : (
    <span
      className={cn(
        'inline-flex min-w-9 items-center justify-center rounded-md px-2 py-1 ring-1 ring-foreground/20',
        !kept && 'opacity-40',
      )}
    >
      {face}
    </span>
  )
}

/**
 * The latest roll as 2D dice that tumble onto the result the server returned. The block is keyed by
 * the entry's id, so only a new roll tumbles; reduced motion turns the tumble off (tableMotion.css).
 */
export function LatestRollView({ roll }: { roll: LatestRoll | null }) {
  return (
    <section aria-label="Latest roll" className="space-y-2 text-sm">
      {roll === null ? (
        <p className="text-muted-foreground">No dice thrown yet.</p>
      ) : (
        <div key={roll.id} className="space-y-2">
          <p className="font-mono text-muted-foreground">{roll.label}</p>
          <ul aria-label="Dice" className="flex flex-wrap gap-2">
            {roll.groups.flatMap((group, groupIndex) =>
              group.dice.map((die, dieIndex) => (
                <li key={`${String(groupIndex)}-${String(dieIndex)}`} className="table-tumble">
                  <RolledDie sides={group.sides} value={die.value} kept={die.kept} />
                </li>
              )),
            )}
          </ul>
          <p role="status" className="text-base font-semibold">
            Total {roll.total}
          </p>
        </div>
      )}
    </section>
  )
}
