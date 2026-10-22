import { useId, useRef, useState, type SubmitEvent } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { Plus, X } from 'lucide-react'

import { Button } from '@/shared/ui/button'
import { IconButton } from '@/shared/ui/icon-button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { Select } from '@/shared/ui/select'

import { KeyHint } from '../KeyHint'
import type { NarrativeItem } from '../narrative/useGameSystemNarrative'
import { StudioError } from '../useGameSystems'
import {
  chaosRange,
  draftProblems,
  toDraft,
  toLevelDraft,
  toOracle,
  type ChaosDraft,
  type LevelDraft,
  type LikelihoodDraft,
} from './likelihoodDraft'
import { parseInteger } from './tableDraft'
import { TestAnswerPanel } from './TestAnswerPanel'
import type { RollHistory } from './useRollHistory'
import {
  OracleError,
  useSaveLikelihoodOracle,
  useTestAskLikelihoodOracle,
  type GameSystemOracles,
  type LikelihoodOracleItem,
  type LikelihoodTest,
} from './useGameSystemOracles'

/**
 * The level a refused save points at, from the path in its message (`…likelihood[0].levels[2]…`).
 * Another oracle's level, or one the draft no longer has, is not this form's row: null.
 */
function levelOfError(message: string, oracleIndex: number, levels: number): number | null {
  const match = /(?:likelihood\[(\d+)\]\.)?levels\[(\d+)\]/.exec(message)
  if (!match) {
    return null
  }
  const index = Number(match[2])
  const sameOracle = match[1] === undefined || Number(match[1]) === oracleIndex
  return sameOracle && index < levels ? index : null
}

/**
 * Whether a refused save is about this oracle's chaos tracker (`…likelihood[0].chaos.tracker: …`).
 * Another oracle's tracker is not this form's field.
 */
function isTrackerError(message: string, oracleIndex: number): boolean {
  const match = /(?:likelihood\[(\d+)\]\.)?chaos\.tracker\b/.exec(message)
  return match !== null && (match[1] === undefined || Number(match[1]) === oracleIndex)
}

const chaosFields = [
  ['min', 'Min'],
  ['max', 'Max'],
  ['neutral', 'Neutral'],
  ['shiftPerPoint', 'Shift per point'],
] as const

/**
 * The counter that holds the chaos factor, picked from the draft's. A stored tracker that is not
 * a counter, or that the draft does not have, stays selected and is flagged; a stored value that
 * is not text is shown, never replaced.
 */
function TrackerField({
  draft,
  stored,
  trackers,
  error,
  onChange,
}: {
  draft: string
  stored: unknown
  trackers: NarrativeItem[]
  error: string | null
  onChange: (tracker: string) => void
}) {
  const id = useId()
  const problemId = useId()
  if (stored !== undefined && typeof stored !== 'string') {
    return (
      <div className="space-y-1 text-sm text-destructive">
        <p>The chaos tracker is not text; it is kept as stored.</p>
        {error !== null && <p role="alert">{error}</p>}
      </div>
    )
  }
  const counters = trackers.filter(({ definition }) => definition.kind === 'counter')
  const known = trackers.find(({ key }) => key === draft)
  const flag =
    draft === '' || counters.some(({ key }) => key === draft)
      ? null
      : known
        ? `The tracker "${draft}" is not a counter.`
        : `The draft has no tracker "${draft}".`
  const name = (item: NarrativeItem) =>
    typeof item.definition.name === 'string' ? item.definition.name : item.key

  return (
    <div className="max-w-64 space-y-2">
      <Label htmlFor={id}>Chaos tracker</Label>
      <Select
        id={id}
        value={draft}
        aria-invalid={flag !== null || error !== null}
        aria-describedby={error === null ? undefined : problemId}
        onChange={(event) => {
          onChange(event.target.value)
        }}
      >
        <option value="">None</option>
        {flag !== null && (
          <option value={draft}>
            {draft} ({known ? 'not a counter' : 'unknown'})
          </option>
        )}
        {counters.map((item) => (
          <option key={item.key} value={item.key}>
            {name(item)}
          </option>
        ))}
      </Select>
      {flag !== null && <p className="text-sm text-destructive">{flag}</p>}
      {error !== null && (
        <p id={problemId} role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
    </div>
  )
}

/** A number field that says it is unreadable. */
function NumberField({
  label,
  value,
  optional = false,
  onChange,
}: {
  label: string
  value: string
  optional?: boolean
  onChange: (value: string) => void
}) {
  return (
    <Label className="w-28 flex-col items-start">
      {label}
      <Input
        inputMode="numeric"
        autoComplete="off"
        aria-label={label}
        aria-invalid={!(optional && value === '') && parseInteger(value) === null}
        value={value}
        onChange={(event) => {
          onChange(event.target.value)
        }}
      />
    </Label>
  )
}

function LevelRow({
  level,
  index,
  problem,
  canRemove,
  onChange,
  onRemove,
}: {
  level: LevelDraft
  index: number
  problem: string | null
  canRemove: boolean
  onChange: (level: LevelDraft) => void
  onRemove: () => void
}) {
  const label = `Level ${String(index + 1)}`
  const problemId = useId()
  const text = (name: 'key' | 'label', invalid: boolean) => ({
    autoComplete: 'off',
    'aria-label': `${label} ${name}`,
    'aria-invalid': invalid,
    'aria-describedby': problem === null ? undefined : problemId,
    value: level[name],
    onChange: (event: { target: { value: string } }) => {
      onChange({ ...level, [name]: event.target.value })
    },
  })

  return (
    <li aria-label={label} className="space-y-2 py-2">
      <div className="flex items-center gap-2">
        <Input className="h-8 w-36 shrink-0 font-mono" {...text('key', level.key.trim() === '')} />
        <Input className="h-8 min-w-0 flex-1" {...text('label', level.label.trim() === '')} />
        <Input
          inputMode="numeric"
          autoComplete="off"
          className="h-8 w-[52px] shrink-0 px-1 text-center"
          aria-label={`${label} target`}
          aria-invalid={parseInteger(level.target) === null}
          value={level.target}
          onChange={(event) => {
            onChange({ ...level, target: event.target.value })
          }}
        />
        <IconButton
          label={`Remove ${label.toLowerCase()}`}
          dense
          destructive
          disabled={!canRemove}
          onClick={onRemove}
        >
          <X />
        </IconButton>
      </div>
      {problem !== null && (
        <p id={problemId} role="alert" className="text-sm text-destructive">
          {problem}
        </p>
      )}
    </li>
  )
}

interface LikelihoodOracleEditorProps {
  gameSystemKey: string
  oracles: GameSystemOracles
  /** The draft's trackers, which the chaos factor picks from; null for a draft without them. */
  trackers: NarrativeItem[] | null
  item: LikelihoodOracleItem
  history: RollHistory<LikelihoodTest>
  /** Told the key a save renames the oracle to before it is sent, and null once it settled. */
  onRenaming: (key: string | null) => void
  /** Refetches the oracles and starts the form again from the latest stored oracle. */
  onReload: () => void
}

/** The form of one likelihood oracle beside its test-answer panel; the draft is the form's own until saved. */
export function LikelihoodOracleEditor({
  gameSystemKey,
  oracles,
  trackers,
  item,
  history,
  onRenaming,
  onReload,
}: LikelihoodOracleEditorProps) {
  const stored = item.definition
  const oracleIndex = oracles.likelihood.findIndex(
    ({ definition }) => definition.key === stored.key,
  )
  const version2 = oracles.schemaVersion >= 2
  const [draft, setDraft] = useState<LikelihoodDraft>(() => toDraft(stored))
  const nextId = useRef(stored.levels.length)
  const save = useSaveLikelihoodOracle(gameSystemKey)
  const ask = useTestAskLikelihoodOracle(gameSystemKey)
  const navigate = useNavigate()
  const keyId = useId()
  const nameId = useId()
  const sidesId = useId()
  const exceptionalId = useId()
  const chaosId = useId()
  const eventId = useId()

  const trimmed = { ...draft, key: draft.key.trim(), name: draft.name.trim() }
  const body = toOracle(stored, trimmed, oracles.schemaVersion)
  const dirty = JSON.stringify(body) !== JSON.stringify(stored)
  const problems = draftProblems(draft)
  const valid = problems.length === 0
  const conflict = save.error instanceof StudioError && save.error.status === 409

  function edit(next: LikelihoodDraft) {
    setDraft(next)
    // What the server said is about the draft as it was sent; a conflict stays until reloaded.
    if (save.isError && !conflict) {
      save.reset()
    }
  }

  /** Saves the edits; the key now stored, or null when the save failed and the form keeps them. */
  async function persist(): Promise<string | null> {
    if (!dirty) {
      return stored.key
    }
    const renames = body.key !== stored.key
    if (renames) {
      onRenaming(body.key)
    }
    try {
      await save.mutateAsync({
        expectedVersion: oracles.version,
        current: stored.key,
        oracle: body,
      })
    } catch {
      onRenaming(null)
      return null
    }
    if (renames) {
      try {
        await navigate({
          to: '/studio/game-systems/$key/likelihood-oracles/$oracle',
          params: { key: gameSystemKey, oracle: body.key },
          replace: true,
        })
      } catch {
        // The save went through; if the router refuses, the URL decides what the page shows.
      } finally {
        onRenaming(null)
      }
    }
    return body.key
  }

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    if (valid && dirty && !save.isPending) {
      void persist()
    }
  }

  const error = save.error
  const levelProblems: Record<number, string> = {}
  let formProblem = error?.message ?? null
  let trackerProblem: string | null = null
  if (error && trackers !== null && draft.chaos && isTrackerError(error.message, oracleIndex)) {
    trackerProblem = error.message
    formProblem = null
  } else if (error) {
    const index = levelOfError(error.message, oracleIndex, draft.levels.length)
    const inUse = error instanceof OracleError && error.paths.length > 0
    if (index !== null && !inUse) {
      levelProblems[index] = error.message
      formProblem = null
    }
  }

  const setChaos = (name: keyof ChaosDraft, value: string) => {
    if (draft.chaos) {
      edit({ ...draft, chaos: { ...draft.chaos, [name]: value } })
    }
  }
  const tables = oracles.tables.map(({ definition }) => definition.key)
  const eventTables =
    draft.randomEvent === '' || tables.includes(draft.randomEvent)
      ? tables
      : [...tables, draft.randomEvent]

  return (
    <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
      <form aria-label="Likelihood oracle" className="space-y-6" onSubmit={handleSubmit} noValidate>
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-2">
            <Label htmlFor={keyId}>Key</Label>
            <Input
              id={keyId}
              value={draft.key}
              autoComplete="off"
              aria-invalid={trimmed.key === ''}
              aria-describedby={`${keyId}-hint`}
              onChange={(event) => {
                edit({ ...draft, key: event.target.value })
              }}
            />
            <KeyHint id={`${keyId}-hint`}>Renaming an oracle the draft uses is refused.</KeyHint>
          </div>
          <div className="space-y-2">
            <Label htmlFor={nameId}>Name</Label>
            <Input
              id={nameId}
              value={draft.name}
              autoComplete="off"
              aria-invalid={trimmed.name === ''}
              onChange={(event) => {
                edit({ ...draft, name: event.target.value })
              }}
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor={sidesId}>Sides</Label>
            <Input
              id={sidesId}
              inputMode="numeric"
              autoComplete="off"
              className="max-w-32"
              value={draft.sides}
              aria-invalid={parseInteger(draft.sides) === null}
              onChange={(event) => {
                edit({ ...draft, sides: event.target.value })
              }}
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor={exceptionalId}>Exceptional percent</Label>
            <Input
              id={exceptionalId}
              inputMode="numeric"
              autoComplete="off"
              className="max-w-32"
              placeholder="0"
              value={draft.exceptionalPercent}
              aria-invalid={
                draft.exceptionalPercent !== '' && parseInteger(draft.exceptionalPercent) === null
              }
              onChange={(event) => {
                edit({ ...draft, exceptionalPercent: event.target.value })
              }}
            />
          </div>
        </div>
        <div className="space-y-3">
          <div aria-hidden="true" className="flex gap-2 group-label">
            <span className="w-36">Key</span>
            <span className="flex-1">Label</span>
            <span className="w-[52px] text-center">Target</span>
          </div>
          <ol aria-label="Levels" className="divide-y">
            {draft.levels.map((level, index) => (
              <LevelRow
                key={level.id}
                level={level}
                index={index}
                problem={levelProblems[index] ?? null}
                canRemove={draft.levels.length > 1}
                onChange={(changed) => {
                  edit({
                    ...draft,
                    levels: draft.levels.map((other) => (other === level ? changed : other)),
                  })
                }}
                onRemove={() => {
                  edit({ ...draft, levels: draft.levels.filter((other) => other !== level) })
                }}
              />
            ))}
          </ol>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => {
              edit({ ...draft, levels: [...draft.levels, toLevelDraft({}, nextId.current++)] })
            }}
          >
            <Plus aria-hidden="true" />
            Add level
          </Button>
        </div>
        <fieldset className="space-y-3">
          <legend className="sr-only">Chaos</legend>
          <div className="flex items-center gap-2">
            <input
              id={chaosId}
              type="checkbox"
              checked={draft.chaos !== null}
              onChange={(event) => {
                edit({
                  ...draft,
                  chaos: event.target.checked
                    ? { min: '', max: '', neutral: '', shiftPerPoint: '', tracker: '' }
                    : null,
                })
              }}
            />
            <Label htmlFor={chaosId}>Has a chaos factor</Label>
          </div>
          {draft.chaos && (
            <div className="flex flex-wrap gap-3">
              {chaosFields.map(([name, label]) => (
                <NumberField
                  key={name}
                  label={`Chaos ${label.toLowerCase()}`}
                  value={draft.chaos?.[name] ?? ''}
                  onChange={(value) => {
                    setChaos(name, value)
                  }}
                />
              ))}
            </div>
          )}
          {draft.chaos && trackers !== null && (
            <TrackerField
              draft={draft.chaos.tracker}
              stored={stored.chaos?.tracker}
              trackers={trackers}
              error={trackerProblem}
              onChange={(tracker) => {
                setChaos('tracker', tracker)
              }}
            />
          )}
        </fieldset>
        {version2 && (
          <div className="max-w-64 space-y-2">
            <Label htmlFor={eventId}>Random event table</Label>
            <Select
              id={eventId}
              value={draft.randomEvent}
              onChange={(event) => {
                edit({ ...draft, randomEvent: event.target.value })
              }}
            >
              <option value="">None</option>
              {eventTables.map((key) => (
                <option key={key} value={key}>
                  {key}
                </option>
              ))}
            </Select>
          </div>
        )}
        {!valid && (
          <ul aria-label="Problems" className="text-sm text-destructive">
            {problems.map((problem) => (
              <li key={problem}>{problem}</li>
            ))}
          </ul>
        )}
        {formProblem !== null && (
          <div role="alert" className="space-y-1 text-sm text-destructive">
            <p>{formProblem}</p>
            {error instanceof OracleError && error.paths.length > 0 && (
              <p className="break-all">Used by {error.paths.join(', ')}.</p>
            )}
            {conflict && (
              <p>Reloading replaces your unsaved edits with the latest stored oracle.</p>
            )}
          </div>
        )}
        {conflict ? (
          <Button type="button" onClick={onReload}>
            Reload the latest version
          </Button>
        ) : (
          <Button type="submit" disabled={!valid || !dirty || save.isPending}>
            Save
          </Button>
        )}
      </form>
      <TestAnswerPanel
        dirty={dirty}
        history={history}
        levels={draft.levels}
        chaos={chaosRange(draft)}
        disabled={!valid || save.isPending || conflict}
        onAsk={async (likelihood, chaosFactor) => {
          const key = await persist()
          return key === null ? null : ask.mutateAsync({ oracle: key, likelihood, chaosFactor })
        }}
      />
    </div>
  )
}
