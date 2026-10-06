import { useId, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'

import type { Campaign } from '../campaigns/useCampaigns'
import { useRecordLikelihoodAnswer, useRecordOracleTableResult } from '../journal/useJournal'

type OracleTable = Campaign['oracleTables'][number]
type LikelihoodOracle = Campaign['likelihoodOracles'][number]

/** The API's limit on a likelihood question (`LikelihoodContent::MAX_QUESTION_LENGTH`), after trimming. */
const MAX_QUESTION_LENGTH = 500

export interface OraclePanelProps {
  campaignId: string
  /** The oracle tables of the campaign's pinned release. */
  tables: OracleTable[]
  /** The likelihood oracles of the campaign's pinned release. */
  likelihoodOracles: LikelihoodOracle[]
  /** Disables every oracle, e.g. while there is no current scene to record the answer in. */
  disabled?: boolean
}

/**
 * Asks the oracles of the campaign's pinned release; every answer is rolled on the server
 * and recorded in the journal, where it shows.
 */
export function OraclePanel({
  campaignId,
  tables,
  likelihoodOracles,
  disabled = false,
}: OraclePanelProps) {
  if (tables.length === 0 && likelihoodOracles.length === 0) {
    return <p className="text-sm text-muted-foreground">This GameSystem release has no oracles.</p>
  }
  return (
    <div className="space-y-6">
      {likelihoodOracles.map((oracle) => (
        <LikelihoodOracleForm
          key={oracle.key}
          campaignId={campaignId}
          oracle={oracle}
          disabled={disabled}
        />
      ))}
      {tables.length > 0 && (
        <OracleTables campaignId={campaignId} tables={tables} disabled={disabled} />
      )}
    </div>
  )
}

function LikelihoodOracleForm({
  campaignId,
  oracle,
  disabled,
}: {
  campaignId: string
  oracle: LikelihoodOracle
  disabled: boolean
}) {
  const middleLevel = oracle.levels[Math.floor((oracle.levels.length - 1) / 2)]
  const [likelihood, setLikelihood] = useState(middleLevel?.key ?? '')
  const [question, setQuestion] = useState('')
  const [chaosFactor, setChaosFactor] = useState(
    oracle.chaos === null ? '' : String(oracle.chaos.neutral),
  )
  const ask = useRecordLikelihoodAnswer(campaignId)
  const headingId = useId()
  const likelihoodId = useId()
  const questionId = useId()
  const chaosId = useId()

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    // Both are optional: an empty field is left out of the request.
    const asked = question.trim()
    const chaos = oracle.chaos !== null && chaosFactor.trim() !== '' ? Number(chaosFactor) : null
    ask.mutate(
      {
        oracleKey: oracle.key,
        likelihood,
        ...(asked === '' ? {} : { question: asked }),
        ...(chaos === null ? {} : { chaosFactor: chaos }),
      },
      {
        onSuccess: () => {
          setQuestion('')
        },
      },
    )
  }

  return (
    <form aria-labelledby={headingId} className="space-y-3" onSubmit={handleSubmit}>
      <h3 id={headingId} className="font-heading font-semibold">
        {oracle.name}
      </h3>
      <div className="space-y-2">
        <Label htmlFor={questionId}>Question (optional)</Label>
        <Input
          id={questionId}
          value={question}
          maxLength={MAX_QUESTION_LENGTH}
          autoComplete="off"
          placeholder="Is the door locked?"
          disabled={disabled}
          onChange={(event) => {
            setQuestion(event.target.value)
          }}
        />
      </div>
      <div className="flex flex-wrap items-end gap-2">
        <div className="min-w-40 flex-1 space-y-2">
          <Label htmlFor={likelihoodId}>Likelihood</Label>
          <NativeSelect
            id={likelihoodId}
            value={likelihood}
            disabled={disabled}
            onChange={(event) => {
              setLikelihood(event.target.value)
            }}
          >
            {oracle.levels.map((level) => (
              <option key={level.key} value={level.key}>
                {level.label}
              </option>
            ))}
          </NativeSelect>
        </div>
        {oracle.chaos !== null && (
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
              disabled={disabled}
              onChange={(event) => {
                setChaosFactor(event.target.value)
              }}
            />
          </div>
        )}
        <Button type="submit" disabled={disabled || likelihood === '' || ask.isPending}>
          Ask
        </Button>
      </div>
      {ask.error && (
        <p role="alert" className="text-sm text-destructive">
          {ask.error.message}
        </p>
      )}
    </form>
  )
}

function OracleTables({
  campaignId,
  tables,
  disabled,
}: {
  campaignId: string
  tables: OracleTable[]
  disabled: boolean
}) {
  const roll = useRecordOracleTableResult(campaignId)
  const headingId = useId()

  return (
    <div className="space-y-3">
      <h3 id={headingId} className="font-heading font-semibold">
        Oracle tables
      </h3>
      <div className="flex flex-wrap gap-2" role="group" aria-labelledby={headingId}>
        {tables.map((table) => (
          <Button
            key={table.key}
            type="button"
            variant="outline"
            size="sm"
            aria-label={`Roll on ${table.name}`}
            disabled={disabled || roll.isPending}
            onClick={() => {
              roll.mutate(table.key)
            }}
          >
            {table.name}
          </Button>
        ))}
      </div>
      {roll.error && (
        <p role="alert" className="text-sm text-destructive">
          {roll.error.message}
        </p>
      )}
    </div>
  )
}
