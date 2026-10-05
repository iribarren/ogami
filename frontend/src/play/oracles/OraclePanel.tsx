import { useId, useState, type ReactNode, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'

import {
  useAskLikelihoodOracle,
  useResolveOracleTable,
  type LikelihoodAnswer,
  type LikelihoodOracleDefinition,
  type OracleTableDefinition,
  type OracleTableResult,
} from './useOracles'

const answerLabels: Record<LikelihoodAnswer['answer'], string> = {
  exceptional_yes: 'Exceptional yes',
  yes: 'Yes',
  no: 'No',
  exceptional_no: 'Exceptional no',
}

// Native select styled like the shadcn Input; no select primitive is installed yet (ADR 0004).
const selectClassName =
  'h-8 w-full min-w-0 rounded-lg border border-input bg-transparent px-2.5 py-1 text-base outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:opacity-50 md:text-sm dark:bg-input/30'

export interface OraclePanelProps {
  /** The likelihood oracle asked yes/no questions. */
  likelihoodOracle: LikelihoodOracleDefinition
  /** The oracle table set; any table can be rolled on, nested tables are followed. */
  tables: OracleTableDefinition[]
  /** Called with every answer of the likelihood oracle. */
  onAnswered?: (answer: LikelihoodAnswer) => void
  /** Called with every resolved oracle table. */
  onResolved?: (result: OracleTableResult) => void
}

/** Asks a likelihood oracle and rolls on oracle tables through the API. */
export function OraclePanel({
  likelihoodOracle,
  tables,
  onAnswered,
  onResolved,
}: OraclePanelProps) {
  return (
    <div className="space-y-6">
      <LikelihoodOracleForm oracle={likelihoodOracle} onAnswered={onAnswered} />
      {tables.length > 0 && <OracleTableForm tables={tables} onResolved={onResolved} />}
    </div>
  )
}

function LikelihoodOracleForm({
  oracle,
  onAnswered,
}: {
  oracle: LikelihoodOracleDefinition
  onAnswered?: (answer: LikelihoodAnswer) => void
}) {
  const middleLevel = oracle.levels[Math.floor((oracle.levels.length - 1) / 2)]
  const [likelihood, setLikelihood] = useState(middleLevel?.key ?? '')
  const [chaosFactor, setChaosFactor] = useState(String(oracle.chaos?.neutral ?? ''))
  const ask = useAskLikelihoodOracle()
  const likelihoodId = useId()
  const chaosId = useId()

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const chosenChaos = oracle.chaos && chaosFactor.trim() !== '' ? Number(chaosFactor) : undefined
    ask.mutate(
      { oracle, likelihood, ...(chosenChaos === undefined ? {} : { chaosFactor: chosenChaos }) },
      { onSuccess: (result) => onAnswered?.(result) },
    )
  }

  return (
    <div className="space-y-4">
      <h3 className="font-heading text-lg font-semibold">Likelihood oracle</h3>
      <form className="flex flex-wrap items-end gap-2" onSubmit={handleSubmit}>
        <div className="min-w-40 flex-1 space-y-2">
          <Label htmlFor={likelihoodId}>Likelihood</Label>
          <select
            id={likelihoodId}
            className={selectClassName}
            value={likelihood}
            onChange={(event) => {
              setLikelihood(event.target.value)
            }}
          >
            {oracle.levels.map((level) => (
              <option key={level.key} value={level.key}>
                {level.label}
              </option>
            ))}
          </select>
        </div>
        {oracle.chaos && (
          <div className="w-28 space-y-2">
            <Label htmlFor={chaosId}>Chaos factor</Label>
            <Input
              id={chaosId}
              type="number"
              inputMode="numeric"
              min={oracle.chaos.min}
              max={oracle.chaos.max}
              step={1}
              value={chaosFactor}
              onChange={(event) => {
                setChaosFactor(event.target.value)
              }}
            />
          </div>
        )}
        <Button type="submit" disabled={ask.isPending}>
          Ask
        </Button>
      </form>

      {ask.error && (
        <p role="alert" className="text-sm text-destructive">
          {ask.error.message}
        </p>
      )}

      {/* Always rendered so screen readers announce each new answer. */}
      <section aria-label="Likelihood answer" aria-live="polite" aria-atomic="true">
        {ask.data && (
          <div className="space-y-3 rounded-xl p-4 ring-1 ring-foreground/10">
            <p className="text-3xl font-bold">{answerLabels[ask.data.answer]}</p>
            <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
              <Detail term="Roll">
                {ask.data.roll} on d{ask.data.sides}
              </Detail>
              <Detail term="Target">{ask.data.effectiveTarget}</Detail>
              <Detail term="Likelihood">{ask.data.likelihoodLabel}</Detail>
              {ask.data.chaosFactor !== null && (
                <Detail term="Chaos factor">{ask.data.chaosFactor}</Detail>
              )}
            </dl>
          </div>
        )}
      </section>
    </div>
  )
}

function Detail({ term, children }: { term: string; children: ReactNode }) {
  return (
    <>
      <dt className="text-muted-foreground">{term}</dt>
      <dd className="tabular-nums">{children}</dd>
    </>
  )
}

function OracleTableForm({
  tables,
  onResolved,
}: {
  tables: OracleTableDefinition[]
  onResolved?: (result: OracleTableResult) => void
}) {
  const [table, setTable] = useState(tables[0]?.key ?? '')
  const resolve = useResolveOracleTable()
  const tableId = useId()

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    resolve.mutate({ tables, table }, { onSuccess: (result) => onResolved?.(result) })
  }

  return (
    <div className="space-y-4">
      <h3 className="font-heading text-lg font-semibold">Oracle tables</h3>
      <form className="flex flex-wrap items-end gap-2" onSubmit={handleSubmit}>
        <div className="min-w-40 flex-1 space-y-2">
          <Label htmlFor={tableId}>Oracle table</Label>
          <select
            id={tableId}
            className={selectClassName}
            value={table}
            onChange={(event) => {
              setTable(event.target.value)
            }}
          >
            {tables.map((definition) => (
              <option key={definition.key} value={definition.key}>
                {definition.name}
              </option>
            ))}
          </select>
        </div>
        <Button type="submit" disabled={resolve.isPending}>
          Roll on table
        </Button>
      </form>

      {resolve.error && (
        <p role="alert" className="text-sm text-destructive">
          {resolve.error.message}
        </p>
      )}

      {/* Always rendered so screen readers announce each new result. */}
      <section aria-label="Oracle table result" aria-live="polite" aria-atomic="true">
        {resolve.data && (
          <ol
            className="space-y-2 rounded-xl p-4 ring-1 ring-foreground/10"
            aria-label="Oracle table steps"
          >
            {resolve.data.steps.map((step, depth) => (
              // Each step after the first rolls on the table its predecessor nests.
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
                <span className="font-mono text-muted-foreground">{step.dice}</span>
                <span className="text-muted-foreground tabular-nums">= {step.total}</span>
                {step.text !== '' && <span className="font-semibold">{step.text}</span>}
              </li>
            ))}
          </ol>
        )}
      </section>
    </div>
  )
}
