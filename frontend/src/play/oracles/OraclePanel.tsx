import { useId, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'

import { useCampaign, type Campaign } from '../campaigns/useCampaigns'
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
  /** The keys of the current Scene's Scene Type shortcuts, in shortcut order; shown first. */
  sceneOracleKeys?: string[]
  /** The campaign's Flow and the keys of its oracles, in panel order; shown after the shortcuts. */
  flow?: { name: string; oracleKeys: string[] } | null
  /** Disables every oracle, e.g. while there is no current scene to record the answer in. */
  disabled?: boolean
}

interface OracleGroup {
  title: string
  tables: OracleTable[]
  likelihoodOracles: LikelihoodOracle[]
}

/**
 * The oracles in the order of ADR 0017: the Scene's shortcuts, the Flow's selection not shown yet,
 * then every other oracle of the release, which the panel collapses. Keys name tables and
 * likelihood oracles alike.
 */
function grouped(
  tables: OracleTable[],
  likelihoodOracles: LikelihoodOracle[],
  sceneKeys: string[],
  flow: OraclePanelProps['flow'],
) {
  const shown = new Set<string>()
  const pick = (title: string, keys: string[]): OracleGroup => {
    const wanted = keys.filter((key) => !shown.has(key))
    const group = {
      title,
      tables: wanted.flatMap((key) => tables.find((table) => table.key === key) ?? []),
      likelihoodOracles: wanted.flatMap(
        (key) => likelihoodOracles.find((oracle) => oracle.key === key) ?? [],
      ),
    }
    for (const { key } of [...group.tables, ...group.likelihoodOracles]) {
      shown.add(key)
    }
    return group
  }
  const first = [
    pick('Scene', sceneKeys),
    ...(flow ? [pick(`Flow: ${flow.name}`, flow.oracleKeys)] : []),
  ].filter(({ tables, likelihoodOracles }) => tables.length + likelihoodOracles.length > 0)
  const more: OracleGroup = {
    title: '',
    tables: tables.filter(({ key }) => !shown.has(key)),
    likelihoodOracles: likelihoodOracles.filter(({ key }) => !shown.has(key)),
  }
  return { first, more }
}

/**
 * Asks the oracles of the campaign's pinned release; every answer is rolled on the server
 * and recorded in the journal, where it shows.
 */
export function OraclePanel({
  campaignId,
  tables,
  likelihoodOracles,
  sceneOracleKeys = [],
  flow = null,
  disabled = false,
}: OraclePanelProps) {
  if (tables.length === 0 && likelihoodOracles.length === 0) {
    return <p className="text-sm text-muted-foreground">This game system release has no oracles.</p>
  }
  const { first, more } = grouped(tables, likelihoodOracles, sceneOracleKeys, flow)
  const everything = { title: '', tables, likelihoodOracles }
  // Neither a Scene Type nor a Flow puts an oracle first: all of them, as before.
  if (first.length === 0) {
    return <OracleGroupView campaignId={campaignId} group={everything} disabled={disabled} />
  }
  return (
    <div className="space-y-6">
      {first.map((group) => (
        <OracleGroupView
          key={group.title}
          campaignId={campaignId}
          group={group}
          disabled={disabled}
        />
      ))}
      {more.tables.length + more.likelihoodOracles.length > 0 && (
        <details className="space-y-6">
          <summary className="cursor-pointer font-heading font-semibold">More oracles</summary>
          <OracleGroupView campaignId={campaignId} group={more} disabled={disabled} />
        </details>
      )}
    </div>
  )
}

/** The likelihood oracles as forms, then the tables as buttons; titled when it is one of several. */
function OracleGroupView({
  campaignId,
  group,
  disabled,
}: {
  campaignId: string
  group: OracleGroup
  disabled: boolean
}) {
  const headingId = useId()
  return (
    <section aria-labelledby={group.title === '' ? undefined : headingId} className="space-y-6">
      {group.title !== '' && (
        <h3
          id={headingId}
          className="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
        >
          {group.title}
        </h3>
      )}
      {group.likelihoodOracles.map((oracle) => (
        <LikelihoodOracleForm
          key={oracle.key}
          campaignId={campaignId}
          oracle={oracle}
          disabled={disabled}
        />
      ))}
      {group.tables.length > 0 && (
        <OracleTables campaignId={campaignId} tables={group.tables} disabled={disabled} />
      )}
    </section>
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
  // An oracle that reads its chaos factor from a Tracker takes none from the player.
  const askChaos = oracle.chaos !== null && oracle.chaosTracker === null
  const [chaosFactor, setChaosFactor] = useState(
    askChaos && oracle.chaos !== null ? String(oracle.chaos.neutral) : '',
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
    const chaos = askChaos && chaosFactor.trim() !== '' ? Number(chaosFactor) : null
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
        {oracle.chaosTracker !== null && (
          <TrackerChaosFactor campaignId={campaignId} trackerKey={oracle.chaosTracker} />
        )}
        {askChaos && oracle.chaos !== null && (
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

/**
 * The chaos factor of an oracle that reads it from a campaign Tracker: the Tracker's current value,
 * read only, from the campaign the page shows (the server reads the same value when asked).
 */
function TrackerChaosFactor({
  campaignId,
  trackerKey,
}: {
  campaignId: string
  trackerKey: string
}) {
  const campaign = useCampaign(campaignId)
  const tracker = campaign.data?.trackers.find(({ key }) => key === trackerKey)

  return (
    <div className="space-y-2">
      <p className="text-sm font-medium">Chaos factor</p>
      <p className="text-sm">
        {tracker === undefined
          ? `From the ${trackerKey} tracker`
          : `${String(tracker.value)} (from the ${tracker.name} tracker)`}
      </p>
    </div>
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
              roll.mutate({ oracleKey: table.key })
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
