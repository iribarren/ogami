import { useId, useRef, useState, type SubmitEvent } from 'react'
import { useNavigate } from '@tanstack/react-router'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'

import { KeyHint } from '../KeyHint'
import type { FieldErrors } from '../narrative/narrativeDraft'
import { mapStepError, type StepError } from '../narrative/steps/stepError'
import type { StepContext } from '../narrative/steps/stepContext'
import { StudioError } from '../useGameSystems'
import { CoverageSummary, EntriesEditor } from './EntriesEditor'
import {
  coverage as coverageOf,
  diceRange,
  numbersValid,
  parseInteger,
  toDraft,
  toEntryDraft,
  toTable,
  type EntryDraft,
  type TableDraft,
} from './tableDraft'
import { TestRollPanel } from './TestRollPanel'
import type { RollHistory } from './useRollHistory'
import {
  OracleError,
  useSaveOracleTable,
  useTestRollOracleTable,
  type GameSystemOracles,
  type OracleTableItem,
  type OracleTableResult,
} from './useGameSystemOracles'

/**
 * The entry a refused save points at, from the path in its message (`…tables[1].entries[3]…`).
 * Another table's entry, or one the draft no longer has, is not this form's row: null.
 */
function entryOfError(message: string, tableIndex: number, entries: number): number | null {
  const match = /(?:tables\[(\d+)\]\.)?entries\[(\d+)\]/.exec(message)
  if (!match) {
    return null
  }
  const index = Number(match[2])
  const sameTable = match[1] === undefined || Number(match[1]) === tableIndex
  return sameTable && index < entries ? index : null
}

/** The client's refusals of effect values (`entries.<n>.effects.<m>.value`), as errors of their entries. */
function clientEntryErrors(errors: FieldErrors): StepError[] {
  return Object.entries(errors).map(([key, message]) => {
    const path = key.split('.').map((part) => (/^\d+$/.test(part) ? `[${part}]` : `.${part}`))
    return mapStepError('entries', `${path.join('').slice(1)}: ${message}`)
  })
}

interface TableEditorProps {
  gameSystemKey: string
  oracles: GameSystemOracles
  /** What entry Scene Types and effects pick from; null for a draft of a schema without them. */
  context: StepContext | null
  item: OracleTableItem
  history: RollHistory<OracleTableResult>
  /** Told the key a save renames the table to before it is sent, and null once it settled. */
  onRenaming: (key: string | null) => void
  /** Refetches the oracles and starts the form again from the latest stored table. */
  onReload: () => void
}

/** The form of one table beside its test-roll panel; the draft is the form's own until saved. */
export function TableEditor({
  gameSystemKey,
  oracles,
  context,
  item,
  history,
  onRenaming,
  onReload,
}: TableEditorProps) {
  const stored = item.definition
  const tableIndex = oracles.tables.findIndex(({ definition }) => definition.key === stored.key)
  const withKey = oracles.schemaVersion >= 2
  const [draft, setDraft] = useState<TableDraft>(() => toDraft(stored))
  const nextId = useRef(stored.entries.length)
  const save = useSaveOracleTable(gameSystemKey)
  const roll = useTestRollOracleTable(gameSystemKey)
  const navigate = useNavigate()
  const keyId = useId()
  const nameId = useId()
  const diceId = useId()

  const trimmed = { ...draft, key: draft.key.trim(), name: draft.name.trim() }
  const effectErrors: FieldErrors = {}
  const body = toTable(stored, trimmed, oracles.schemaVersion, effectErrors)
  const dirty = JSON.stringify(body) !== JSON.stringify(stored)
  const range = draft.ranged ? diceRange(draft.dice) : null
  const coverage =
    range &&
    coverageOf(
      range,
      draft.entries.map(({ min, max }) => ({ min: parseInteger(min), max: parseInteger(max) })),
    )
  const diceMissing = draft.ranged && draft.dice.trim() === ''
  const valid =
    trimmed.key !== '' &&
    trimmed.name !== '' &&
    !diceMissing &&
    numbersValid(draft) &&
    Object.keys(effectErrors).length === 0 &&
    draft.entries.length > 0 &&
    draft.entries.every(({ text }) => text.trim() !== '')

  const conflict = save.error instanceof StudioError && save.error.status === 409

  function edit(next: TableDraft) {
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
        table: body,
      })
    } catch {
      onRenaming(null)
      return null
    }
    if (renames) {
      try {
        await navigate({
          to: '/studio/game-systems/$key/oracle-tables/$table',
          params: { key: gameSystemKey, table: body.key },
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
  const problems: Record<number, string> = {}
  coverage?.outside.forEach((index) => (problems[index] = 'This range reaches outside the dice.'))
  coverage?.invalid.forEach(
    (index) => (problems[index] = 'Min and max must be whole numbers, min at most max.'),
  )
  const entryErrors: Record<number, StepError[]> = {}
  clientEntryErrors(effectErrors).forEach((about) => {
    if (about.index !== null) {
      entryErrors[about.index] = [...(entryErrors[about.index] ?? []), about]
    }
  })
  let formProblem = error?.message ?? null
  if (error) {
    const index = entryOfError(error.message, tableIndex, draft.entries.length)
    const inUse = error instanceof OracleError && error.paths.length > 0
    if (index !== null && !inUse) {
      // Messages about the Scene Type or the effects of the entry go to those fields.
      const about = mapStepError(`oracles.tables[${String(tableIndex)}].entries`, error.message)
      if (
        context !== null &&
        about.index === index &&
        (about.effect !== null || about.field === 'sceneType' || about.field === 'effects')
      ) {
        entryErrors[index] = [...(entryErrors[index] ?? []), about]
      } else {
        problems[index] = error.message
      }
      formProblem = null
    }
  }

  return (
    <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
      <form aria-label="Oracle table" className="space-y-6" onSubmit={handleSubmit} noValidate>
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
            <KeyHint id={`${keyId}-hint`}>Renaming a table the draft uses is refused.</KeyHint>
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
        </div>
        {draft.ranged && (
          <div className="space-y-2">
            <Label htmlFor={diceId}>Dice</Label>
            <Input
              id={diceId}
              value={draft.dice}
              autoComplete="off"
              className="max-w-40 font-mono"
              aria-invalid={diceMissing}
              onChange={(event) => {
                edit({ ...draft, dice: event.target.value })
              }}
            />
            {diceMissing && <p className="text-sm text-destructive">Enter dice like 1d6 or 2d6.</p>}
            {coverage ? (
              <CoverageSummary coverage={coverage} />
            ) : (
              !diceMissing && (
                <p className="text-sm text-muted-foreground">
                  Coverage is shown for dice like 1d6, 2d6 or d%; the server checks any other.
                </p>
              )
            )}
          </div>
        )}
        <EntriesEditor
          entries={draft.entries}
          problems={problems}
          ranged={draft.ranged}
          withKey={withKey}
          tables={oracles.tables
            .map(({ definition }) => definition.key)
            .filter((key) => key !== stored.key)}
          context={context}
          errors={entryErrors}
          onChange={(entries: EntryDraft[]) => {
            edit({ ...draft, entries })
          }}
          onAdd={() => {
            const entry = toEntryDraft({}, nextId.current++)
            edit({
              ...draft,
              entries: [...draft.entries, draft.ranged ? entry : { ...entry, weight: '1' }],
            })
          }}
        />
        {formProblem !== null && (
          <div role="alert" className="space-y-1 text-sm text-destructive">
            <p>{formProblem}</p>
            {error instanceof OracleError && error.paths.length > 0 && (
              <p className="break-all">Used by {error.paths.join(', ')}.</p>
            )}
            {conflict && <p>Reloading replaces your unsaved edits with the latest stored table.</p>}
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
      <TestRollPanel
        dirty={dirty}
        history={history}
        disabled={!valid || save.isPending || conflict}
        onRoll={async () => {
          const key = await persist()
          return key === null ? null : roll.mutateAsync(key)
        }}
      />
    </div>
  )
}
