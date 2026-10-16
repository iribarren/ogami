import { useId, useRef, useState, type SubmitEvent } from 'react'
import { useNavigate } from '@tanstack/react-router'

import { Badge } from '@/shared/ui/badge'
import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { NativeSelect } from '@/shared/ui/native-select'

import { StudioError } from '../useGameSystems'
import {
  draftProblems,
  toDraft,
  toLevelDraft,
  toOracle,
  type ChaosDraft,
  type LevelDraft,
  type LikelihoodDraft,
} from './likelihoodDraft'
import { parseInteger } from './tableDraft'
import {
  OracleError,
  useSaveLikelihoodOracle,
  type GameSystemOracles,
  type LikelihoodOracleItem,
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

const chaosFields = [
  ['min', 'Min'],
  ['max', 'Max'],
  ['neutral', 'Neutral'],
  ['shiftPerPoint', 'Shift per point'],
] as const

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
    <li aria-label={label} className="space-y-2 px-4 py-3">
      <div className="flex flex-wrap items-end gap-3">
        <div className="min-w-40 space-y-1">
          <Label>
            Level key
            <Input {...text('key', level.key.trim() === '')} />
          </Label>
        </div>
        <div className="min-w-40 flex-1 space-y-1">
          <Label>
            Level label
            <Input {...text('label', level.label.trim() === '')} />
          </Label>
        </div>
        <div className="w-24 space-y-1">
          <Label>
            Level target
            <Input
              inputMode="numeric"
              autoComplete="off"
              aria-label={`${label} target`}
              aria-invalid={parseInteger(level.target) === null}
              value={level.target}
              onChange={(event) => {
                onChange({ ...level, target: event.target.value })
              }}
            />
          </Label>
        </div>
        <Button type="button" variant="outline" size="sm" disabled={!canRemove} onClick={onRemove}>
          Remove {label.toLowerCase()}
        </Button>
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
  item: LikelihoodOracleItem
  /** Told the key a save renames the oracle to before it is sent, and null once it settled. */
  onRenaming: (key: string | null) => void
  /** Refetches the oracles and starts the form again from the latest stored oracle. */
  onReload: () => void
}

/** The form of one likelihood oracle beside its test-answer panel; the draft is the form's own until saved. */
export function LikelihoodOracleEditor({
  gameSystemKey,
  oracles,
  item,
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
  const tracker = stored.chaos?.tracker

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
  if (error) {
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
    <div className="max-w-3xl">
      <form aria-label="Likelihood oracle" className="space-y-6" onSubmit={handleSubmit} noValidate>
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-2">
            <Label htmlFor={keyId}>Key</Label>
            <Input
              id={keyId}
              value={draft.key}
              autoComplete="off"
              aria-invalid={trimmed.key === ''}
              onChange={(event) => {
                edit({ ...draft, key: event.target.value })
              }}
            />
            <p className="text-sm text-muted-foreground">
              Renaming an oracle the draft uses is refused.
            </p>
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
          <ol aria-label="Levels" className="divide-y rounded-xl ring-1 ring-foreground/10">
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
                    ? { min: '', max: '', neutral: '', shiftPerPoint: '' }
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
          {typeof tracker === 'string' && version2 && (
            <p className="text-sm text-muted-foreground">
              Chaos tracker <Badge variant="secondary">{tracker}</Badge> (not edited here)
            </p>
          )}
        </fieldset>
        {version2 && (
          <div className="max-w-64 space-y-2">
            <Label htmlFor={eventId}>Random event table</Label>
            <NativeSelect
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
            </NativeSelect>
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
    </div>
  )
}
