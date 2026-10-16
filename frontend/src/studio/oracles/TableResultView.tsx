import type { OracleTableResult } from './useGameSystemOracles'

/** One result: a step per table rolled, each nested one indented under the roll that led to it. */
export function TableResultView({ result }: { result: OracleTableResult }) {
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
