import type { OracleTableResult } from './useGameSystemOracles'

/**
 * One result: the first roll's total as a read-out, then a step per table rolled, each nested one
 * indented under the roll that led to it. The read-out repeats a step's total, so it is hidden
 * from assistive technology.
 */
export function TableResultView({ result }: { result: OracleTableResult }) {
  return (
    <div className="flex items-start gap-3.5">
      {result.steps[0] && (
        <p aria-hidden="true" className="readout min-w-10 text-center tabular-nums">
          {result.steps[0].total}
        </p>
      )}
      <ol className="min-w-0 flex-1 space-y-1" aria-label="Steps">
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
            <span className="font-mono text-xs break-all text-muted-foreground">
              {step.tableKey}
            </span>
            <span className="font-mono text-muted-foreground">{step.dice}</span>
            <span className="text-muted-foreground tabular-nums">= {step.total}</span>
            {step.text !== '' && <span className="text-[13px] font-[550]">{step.text}</span>}
          </li>
        ))}
      </ol>
    </div>
  )
}
