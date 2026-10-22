import { useState } from 'react'

import { cn } from '@/shared/lib/utils'
import { Button } from '@/shared/ui/button'
import { DieShape } from '@/shared/ui/die-shape'
import { IconTile } from '@/shared/ui/icon-tile'

import { TableResultView } from './TableResultView'
import type { RollHistory } from './useRollHistory'
import type { OracleTableResult } from './useGameSystemOracles'

/**
 * Rolls the saved table. `onRoll` saves the edits first when there are any and answers null when
 * that failed (the form says why); a refused roll throws and its message shows here.
 */
export function TestRollPanel({
  dirty,
  disabled,
  history,
  onRoll,
}: {
  dirty: boolean
  disabled: boolean
  history: RollHistory<OracleTableResult>
  onRoll: () => Promise<OracleTableResult | null>
}) {
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function roll() {
    setBusy(true)
    setError(null)
    try {
      const result = await onRoll()
      if (result) {
        history.add(result)
      }
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : 'Rolling the table failed.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <section aria-label="Test roll" className="panel">
      <div className="panel-head">
        <IconTile size="sm">
          <DieShape sides={20} inner />
        </IconTile>
        <h2 className="font-heading text-panel-title">Test roll</h2>
      </div>
      <div className="space-y-3 panel-body">
        <p className="text-sm text-muted-foreground">
          Rolls the saved table; nothing is recorded.
          {dirty && ' Your unsaved edits are saved first.'}
        </p>
        <Button
          type="button"
          disabled={disabled || busy}
          onClick={() => {
            void roll()
          }}
        >
          <DieShape sides={20} inner />
          Test roll
        </Button>
        {error !== null && (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        )}
        {history.results.length > 0 && (
          <ul aria-label="Test roll results" className="space-y-2">
            {history.results.map(({ id, result }, position) => (
              // The latest result is ringed; older ones fade as they age.
              <li
                key={id}
                className={cn(
                  'well',
                  position === 0 && 'ring-1 ring-primary',
                  position === 1 && 'opacity-75',
                  position > 1 && 'opacity-60',
                )}
              >
                <TableResultView result={result} />
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  )
}
