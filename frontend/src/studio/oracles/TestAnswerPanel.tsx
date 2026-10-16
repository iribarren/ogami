import { useId, useState } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'

import type { LevelDraft } from './likelihoodDraft'
import { parseInteger } from './tableDraft'
import { TableResultView } from './TableResultView'
import type { LikelihoodTest } from './useGameSystemOracles'
import type { RollHistory } from './useRollHistory'

const answers = {
  exceptional_yes: 'Exceptional yes',
  yes: 'Yes',
  no: 'No',
  exceptional_no: 'Exceptional no',
}

/** One answer: the roll against the target, and the random event it signalled with its table roll. */
function AnswerView({ test: { answer, randomEvent } }: { test: LikelihoodTest }) {
  return (
    <div className="space-y-1 text-sm">
      <p>
        <span className="font-semibold">{answers[answer.answer]}</span>{' '}
        <span className="text-muted-foreground tabular-nums">
          rolled {answer.roll} on 1d{answer.sides} against {answer.effectiveTarget}
        </span>
      </p>
      <p className="text-muted-foreground">
        {answer.likelihoodLabel}
        {answer.chaosFactor !== null && `, chaos factor ${String(answer.chaosFactor)}`}
      </p>
      {answer.randomEvent !== null && (
        <p className="font-medium">
          Random event: <span className="font-mono">{answer.randomEvent}</span>
        </p>
      )}
      {randomEvent && <TableResultView result={randomEvent} />}
    </div>
  )
}

/**
 * Asks the saved oracle. `onAsk` saves the edits first when there are any and answers null when
 * that failed (the form says why); a refused question throws and its message shows here.
 */
export function TestAnswerPanel({
  dirty,
  disabled,
  history,
  levels,
  chaos,
  onAsk,
}: {
  dirty: boolean
  disabled: boolean
  history: RollHistory<LikelihoodTest>
  levels: LevelDraft[]
  /** The chaos factors the oracle accepts; null when it has no chaos. */
  chaos: { min: number; max: number } | null
  onAsk: (likelihood: string, chaosFactor: number | null) => Promise<LikelihoodTest | null>
}) {
  const [chosen, setChosen] = useState('')
  const [factor, setFactor] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const levelId = useId()
  const factorId = useId()

  // A level that was renamed or removed since it was chosen falls back to the first.
  const level = levels.some(({ key }) => key === chosen) ? chosen : (levels[0]?.key ?? '')
  const parsed = parseInteger(factor)
  const factorProblem =
    chaos !== null && factor !== '' && (parsed === null || parsed < chaos.min || parsed > chaos.max)
      ? `The chaos factor is a whole number from ${String(chaos.min)} to ${String(chaos.max)}.`
      : null

  async function ask() {
    setBusy(true)
    setError(null)
    try {
      const test = await onAsk(level, chaos === null || factor === '' ? null : parsed)
      if (test) {
        history.add(test)
      }
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : 'Asking the oracle failed.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <section
      aria-label="Test answer"
      className="space-y-3 rounded-xl p-4 ring-1 ring-foreground/10"
    >
      <h2 className="font-heading text-lg font-semibold">Test answer</h2>
      <p className="text-sm text-muted-foreground">
        Asks the saved oracle; nothing is recorded.
        {dirty && ' Your unsaved edits are saved first.'}
      </p>
      <div className="space-y-2">
        <Label htmlFor={levelId}>Likelihood</Label>
        <NativeSelect
          id={levelId}
          value={level}
          onChange={(event) => {
            setChosen(event.target.value)
          }}
        >
          {levels.map(({ id, key, label }) => (
            <option key={id} value={key}>
              {label} ({key})
            </option>
          ))}
        </NativeSelect>
      </div>
      {chaos !== null && (
        <div className="space-y-2">
          <Label htmlFor={factorId}>Chaos factor</Label>
          <Input
            id={factorId}
            inputMode="numeric"
            autoComplete="off"
            className="max-w-32"
            placeholder="Neutral"
            value={factor}
            aria-invalid={factorProblem !== null}
            onChange={(event) => {
              setFactor(event.target.value)
            }}
          />
          {factorProblem !== null && <p className="text-sm text-destructive">{factorProblem}</p>}
        </div>
      )}
      <Button
        type="button"
        disabled={disabled || busy || level === '' || factorProblem !== null}
        onClick={() => {
          void ask()
        }}
      >
        Ask
      </Button>
      {error !== null && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
      {history.results.length > 0 && (
        <ul aria-label="Test answer results" className="divide-y">
          {history.results.map(({ id, result }) => (
            <li key={id} className="py-2">
              <AnswerView test={result} />
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
