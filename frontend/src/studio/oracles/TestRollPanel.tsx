import { useState } from 'react'

import { Button } from '@/shared/ui/button'

import type { RollHistory } from './useRollHistory'
import type { OracleTableResult } from './useGameSystemOracles'

/** One result: a step per table rolled, each nested one indented under the roll that led to it. */
function ResultView({ result }: { result: OracleTableResult }) {
  return (
    <ol className="space-y-1" aria-label="Steps">
      {result.steps.map((step, depth) => (
        <li
          key={depth}
          className="flex flex-wrap items-baseline gap-x-2 text-sm"
          style={{ paddingLeft: `${String(depth * 1.25)}rem` }}
        >
          {depth > 0 && (
            <>
              <span aria-hidden="true" className="text-muted-foreground">
                ↳
              </span>
              <span className="sr-only">Nested roll:</span>
            </>
          )}
          <span className="font-medium">{step.tableName}</span>
          <span className="font-mono text-xs break-all text-muted-foreground">{step.tableKey}</span>
          <span className="font-mono text-muted-foreground">{step.dice}</span>
          <span className="text-muted-foreground tabular-nums">= {step.total}</span>
          {step.text !== '' && <span className="font-semibold">{step.text}</span>}
        </li>
      ))}
    </ol>
  )
}

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
  history: RollHistory
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
    <section aria-label="Test roll" className="space-y-3 rounded-xl p-4 ring-1 ring-foreground/10">
      <h2 className="font-heading text-lg font-semibold">Test roll</h2>
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
        Test roll
      </Button>
      {error !== null && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
      {history.results.length > 0 && (
        <ul aria-label="Test roll results" className="divide-y">
          {history.results.map(({ id, result }) => (
            <li key={id} className="py-2">
              <ResultView result={result} />
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
