import { useId, useMemo, useState } from 'react'

import { cn } from '@/shared/lib/utils'
import { Badge } from '@/shared/ui/badge'
import { Button } from '@/shared/ui/button'
import { DieShape } from '@/shared/ui/die-shape'
import { IconTile } from '@/shared/ui/icon-tile'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'

import { previewTemplate } from '../sheet/previewTemplate'
import { useGameSystemSheet } from '../sheet/useGameSystemSheet'
import type { SampleValues } from '../sheet/useSheetSamples'
import { useRollHistory } from '../oracles/useRollHistory'
import type { BandDraft, DifficultyDraft, PoolDraft } from './checkDraft'
import { resolveSandbox, type Bound, type SandboxInput, type SandboxRequest } from './checkSandbox'
import {
  useTestPushCheck,
  useTestRollCheck,
  type CheckTestRoll,
  type GameSystemChecks,
} from './useGameSystemChecks'

/** How a band's bound reads: a number, "the rest" for the last band, or a dash when unknown. */
function boundText(bound: Bound) {
  switch (bound.kind) {
    case 'value':
      return `up to ${String(bound.value)}`
    case 'rest':
      return 'the rest'
    case 'unknown':
      return '—'
  }
}

/**
 * Rolls the saved check against sample values: the number and track fields and Trackers (the ones
 * the Sheet tab's preview shares), a difficulty and a situational modifier. The band bounds follow
 * the form and the samples as they are typed, computed here; the roll itself is the server's, on
 * the saved check, so unsaved edits are saved first (`ensureSaved`). The last 5 results are kept.
 * A pool check's result can be pushed when its check declares a push and a die failed: the same
 * samples go back with the faces, and the pushed roll joins the results.
 */
export function CheckSandbox({
  gameSystemKey,
  catalog,
  bands,
  difficulty,
  pool,
  sampleValues,
  dirty,
  ensureSaved,
}: {
  gameSystemKey: string
  catalog: Pick<GameSystemChecks, 'fields' | 'trackers'>
  /** The bands as the form holds them. */
  bands: BandDraft[]
  /** The difficulty the form declares, or null. */
  difficulty: DifficultyDraft | null
  /** The pool the form declares, or null for a dice check. */
  pool: PoolDraft | null
  sampleValues: SampleValues
  /** Whether the form holds edits that are not saved. */
  dirty: boolean
  /** Saves the form's edits when there are any: the saved check's key, or null when that failed. */
  ensureSaved: () => Promise<string | null>
}) {
  const sheet = useGameSystemSheet(gameSystemKey)
  const template = useMemo(
    () => (sheet.data === undefined ? [] : previewTemplate(sheet.data, null).fields),
    [sheet.data],
  )
  const [difficultyText, setDifficultyText] = useState<string | null>(null)
  const [situationalText, setSituationalText] = useState('0')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const history = useRollHistory<SandboxRoll>()
  const rollCheck = useTestRollCheck(gameSystemKey)
  const pushCheck = useTestPushCheck(gameSystemKey)

  const sandbox = resolveSandbox({
    catalog,
    template,
    samples: sampleValues.samples,
    trackerSamples: sampleValues.trackerSamples,
    difficulty,
    difficultyText,
    situationalText,
    bands,
    pool,
  })
  const { request } = sandbox

  async function roll() {
    if (request === null) {
      return
    }
    setBusy(true)
    setError(null)
    try {
      // Nothing is rolled unless the edits are saved: the server rolls the saved check.
      const saved = await ensureSaved()
      if (saved === null) {
        setError('The check was not saved, so it was not rolled.')
        return
      }
      const result = await rollCheck.mutateAsync({ check: saved, request })
      history.add({ check: saved, request, result })
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : 'Rolling the check failed.')
    } finally {
      setBusy(false)
    }
  }

  /** Pushes a pool roll with the samples it was rolled with, sending back the face of each die. */
  async function push({ check, request, result }: SandboxRoll) {
    if (result.pool === null) {
      return
    }
    setBusy(true)
    setError(null)
    try {
      const faces = result.pool.dice.map(({ face }) => face)
      history.add({
        check,
        request,
        result: await pushCheck.mutateAsync({ check, request: { ...request, faces } }),
      })
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : 'Pushing the roll failed.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <section aria-label="Test roll sandbox" className="panel">
      <div className="panel-head">
        <IconTile size="sm">
          <DieShape sides={20} inner />
        </IconTile>
        <h3 className="font-heading text-panel-title">Test roll</h3>
      </div>
      <div className="space-y-4 panel-body">
        <p className="text-sm text-muted-foreground">
          Rolls the saved check with these sample values, which are never saved; nothing is
          recorded. The Sheet tab&apos;s preview shares them.
          {dirty && ' Your unsaved edits are saved first.'} An empty input takes the default shown
          in it.
        </p>
        {sheet.data === undefined && !sheet.isPending && (
          <p className="text-sm text-muted-foreground">
            The sheet could not be read, so a number&apos;s default is not known here: leave it
            empty to let the server apply it.
          </p>
        )}
        {(sandbox.fields.length > 0 || sandbox.trackers.length > 0) && (
          <fieldset className="space-y-2">
            <legend className="text-sm font-medium">Sample values</legend>
            <div className="grid gap-3 sm:grid-cols-2">
              {sandbox.fields.map((input) => (
                <SampleInputField
                  key={input.key}
                  input={input}
                  onChange={(value) => {
                    const field = catalog.fields.find(({ key }) => key === input.key)
                    // The max of a track is shared with the Sheet preview: keep the one typed there.
                    const held = sampleValues.samples[input.key]?.draft
                    const heldMax =
                      typeof held === 'object' && !Array.isArray(held) ? held.max : undefined
                    sampleValues.setSample(
                      input.key,
                      field?.type === 'track'
                        ? {
                            type: 'track',
                            draft: { current: value, max: heldMax ?? String(field.max ?? 0) },
                          }
                        : { type: 'number', draft: value },
                    )
                  }}
                />
              ))}
              {sandbox.trackers.map((input) => (
                <SampleInputField
                  key={`tracker:${input.key}`}
                  input={{ ...input, label: `${input.label} (Tracker)` }}
                  onChange={(value) => {
                    sampleValues.setTrackerSample(input.key, value)
                  }}
                />
              ))}
            </div>
          </fieldset>
        )}
        <div className="grid gap-3 sm:grid-cols-2">
          {sandbox.difficulty !== null && (
            <SampleInputField input={sandbox.difficulty} onChange={setDifficultyText} />
          )}
          <SampleInputField input={sandbox.situational} onChange={setSituationalText} />
        </div>
        {pool !== null && sandbox.poolSize !== null && (
          <p className="text-sm">
            The pool rolls {sandbox.poolSize} {sandbox.poolSize === 1 ? 'die' : 'dice'}
            {pool.sides.trim() !== '' && <> of {pool.sides.trim()} sides</>}.
          </p>
        )}
        <div className="space-y-2 well">
          <h4 className="text-sm font-medium">Band bounds for these samples</h4>
          <ul aria-label="Band bounds" className="space-y-1 text-sm">
            {bands.map((band, index) => (
              // The bands have no identity of their own, so the position is the key.
              <li key={index} className="flex flex-wrap gap-x-3">
                <span className="font-medium">
                  {band.label.trim() || band.key.trim() || `Band ${String(index + 1)}`}
                </span>
                <span className="text-muted-foreground tabular-nums">
                  {boundText(sandbox.bounds[index] ?? { kind: 'unknown' })}
                </span>
              </li>
            ))}
          </ul>
          {sandbox.warning !== null && (
            <p role="status" className="flex flex-wrap items-center gap-2 text-sm">
              <Badge variant="warning">Warning</Badge>
              {sandbox.warning}
            </p>
          )}
        </div>
        <Button
          type="button"
          disabled={request === null || busy}
          onClick={() => {
            void roll()
          }}
        >
          <DieShape sides={20} inner />
          Roll the check
        </Button>
        {request === null && (
          <p className="text-sm text-muted-foreground">Fix the sample values above to roll.</p>
        )}
        {error !== null && (
          <p role="alert" className="text-sm text-destructive">
            {error}
          </p>
        )}
        {history.results.length > 0 && (
          <ul aria-label="Test roll results" className="space-y-2">
            {history.results.map(({ id, result: entry }, position) => (
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
                <RollResult
                  result={entry.result}
                  busy={busy}
                  onPush={() => {
                    void push(entry)
                  }}
                />
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  )
}

/** One labelled sample input; what is wrong with it shows under it. */
function SampleInputField({
  input,
  onChange,
}: {
  input: SandboxInput
  onChange: (value: string) => void
}) {
  const id = useId()
  const errorId = useId()

  return (
    <div className="space-y-1">
      <Label htmlFor={id}>{input.label}</Label>
      <Input
        id={id}
        className="w-32"
        inputMode="numeric"
        autoComplete="off"
        value={input.value}
        placeholder={input.placeholder}
        aria-invalid={input.error !== null}
        aria-describedby={input.error === null ? undefined : errorId}
        onChange={(event) => {
          onChange(event.target.value)
        }}
      />
      {input.error !== null && (
        <p id={errorId} className="text-sm text-destructive">
          {input.error}
        </p>
      )}
    </div>
  )
}

/** One roll kept in the history: the saved check it rolled and the samples sent, which a push sends back. */
interface SandboxRoll {
  check: string
  request: SandboxRequest
  result: CheckTestRoll
}

type TestPool = NonNullable<CheckTestRoll['pool']>

/** The band a number of successes reaches: the first whose bound is at least the number, else the last. */
function bandOf(bands: CheckTestRoll['bands'], successes: number) {
  return bands.find((band) => band.bound === null || successes <= band.bound)
}

/** One test roll: the total and the band reached, the dice and modifiers, every band with the reached one marked. */
function RollResult({
  result,
  busy,
  onPush,
}: {
  result: CheckTestRoll
  busy: boolean
  onPush: () => void
}) {
  const { dice, pool, push } = result
  const reached = result.bands.find((band) => band.key === result.band)
  const rolled =
    result.rolledSuccesses === null ? undefined : bandOf(result.bands, result.rolledSuccesses)
  // A pushed roll cannot be pushed again; before a push, a failed die is what a push re-rolls.
  const pushable =
    pool !== null &&
    push !== null &&
    result.rolledSuccesses === null &&
    pool.dice.some((die) => !die.success)

  return (
    <div className="space-y-2 text-sm">
      <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1">
        <p className="text-[28px] font-bold tracking-[-0.02em] tabular-nums">
          {pool === null
            ? `Total ${String(result.total)}`
            : `${String(result.total)} ${result.total === 1 ? 'success' : 'successes'}`}
        </p>
        {reached && <p className="text-[13px] font-[550]">{reached.label}</p>}
      </div>
      {push !== null && result.rolledSuccesses !== null && (
        <p>
          <strong>Pushed: {push.label}</strong>
          {rolled && reached && (
            <>
              {' · '}
              <span>
                {rolled.key === reached.key
                  ? `Rolled ${rolled.label}, still ${reached.label}`
                  : `Rolled ${rolled.label}, pushed to ${reached.label}`}
              </span>
            </>
          )}
          <span className="block text-muted-foreground">
            The cost is not applied in a test roll.
          </span>
        </p>
      )}
      {pool !== null && <PoolDice pool={pool} />}
      <p className="flex flex-wrap items-baseline gap-x-3 font-mono text-xs text-muted-foreground">
        {dice !== null && (
          <>
            <span className="font-mono">{dice.expression}</span>
            <span className="tabular-nums">= {dice.total}</span>
            {dice.groups.map((group, position) => (
              <span key={position}>
                {group.notation}:{' '}
                {group.dice
                  .map((die) => (die.kept ? String(die.value) : `(${String(die.value)})`))
                  .join(' ')}
              </span>
            ))}
          </>
        )}
        {pool !== null && (
          <>
            <span>
              {String(pool.size)} d{pool.sides}, success on {pool.successAt}+
            </span>
            <span>
              {pool.countFormula === null
                ? pool.count
                : `${pool.countFormula} = ${String(pool.count)}`}
            </span>
          </>
        )}
        {pool === null && <span>Modifier {result.modifier}</span>}
        <span>Situational {result.situational}</span>
        {result.difficulty !== null && <span>Difficulty {result.difficulty}</span>}
      </p>
      {pushable && (
        <div className="flex flex-wrap items-center gap-3">
          <Button type="button" variant="outline" size="sm" disabled={busy} onClick={onPush}>
            Push the roll
          </Button>
          <span className="text-muted-foreground">Cost: {push.label}</span>
        </div>
      )}
      <ol aria-label="Bands" className="space-y-1">
        {result.bands.map((band) => {
          const isReached = band.key === result.band
          return (
            <li
              key={band.key}
              aria-current={isReached ? 'true' : undefined}
              className="flex flex-wrap items-center gap-x-3 rounded-md px-2 py-1 aria-[current=true]:bg-muted aria-[current=true]:font-semibold"
            >
              <span>{band.label}</span>
              <span className="text-muted-foreground tabular-nums">
                {band.bound === null ? 'the rest' : `up to ${String(band.bound)}`}
              </span>
              {isReached && <Badge variant="success">Reached</Badge>}
            </li>
          )
        })}
      </ol>
    </div>
  )
}

/**
 * Every die of a pool roll; a success is told by text for assistive tech and by weight and a
 * border, and a die a push re-rolled by text too, not by colour alone.
 */
function PoolDice({ pool }: { pool: TestPool }) {
  return (
    <ul aria-label="Pool dice" className="flex flex-wrap gap-1.5">
      {pool.dice.map((die, index) => (
        <li
          // A pool can show the same face many times; the position is the identity.
          key={index}
          className={
            die.success
              ? 'min-w-8 rounded-md border-2 border-foreground px-1.5 py-0.5 text-center font-bold'
              : 'min-w-8 rounded-md border border-dashed border-muted-foreground px-1.5 py-0.5 text-center text-muted-foreground'
          }
        >
          {die.face}
          <span className="sr-only">{die.success ? ' (success)' : ' (no success)'}</span>
          {die.rerolled && <span className="block text-xs font-normal">re-rolled</span>}
        </li>
      ))}
    </ul>
  )
}
