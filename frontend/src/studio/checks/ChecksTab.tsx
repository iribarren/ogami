import { useId, useMemo, useState, type SubmitEvent } from 'react'

import { Badge } from '@/shared/ui/badge'
import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/shared/ui/dialog'
import { Label } from '@/shared/ui/label'

import { TextField } from '../narrative/TextField'
import { FormulaInput, type ReferenceOption } from '../sheet/FormulaInput'
import { StudioError } from '../useGameSystems'
import {
  blankCheck,
  blankDifficulty,
  describeBands,
  readCheck,
  writeCheck,
  type CheckDraft,
  type CheckErrors,
  type DifficultyDraft,
} from './checkDraft'
import { checkAnswer } from './checkError'
import {
  CheckError,
  useDeleteCheck,
  useGameSystemChecks,
  useMoveCheck,
  useSaveCheck,
  type GameSystemChecks,
} from './useGameSystemChecks'

/** The checks of a draft: a list to move and delete, and an editor panel for one check at a time. */
export function ChecksTab({ gameSystemKey }: { gameSystemKey: string }) {
  const checks = useGameSystemChecks(gameSystemKey)

  if (checks.data === undefined) {
    if (checks.isPending) {
      return <p className="text-muted-foreground">Loading the checks…</p>
    }
    return (
      <p role="alert" className="text-sm text-destructive">
        {checks.error instanceof StudioError && checks.error.status === 404
          ? 'This GameSystem does not exist, or it is not one of yours.'
          : checks.error.message}
      </p>
    )
  }

  if (checks.data.schemaVersion < 2) {
    return (
      <p className="text-muted-foreground">
        Schema version 1 drafts have no checks: they exist only in schema version 2 drafts.
      </p>
    )
  }

  return (
    <>
      {checks.isRefetchError && (
        <p role="alert" className="text-sm text-destructive">
          Couldn&apos;t refresh the checks. {checks.error.message}
        </p>
      )}
      <CheckList gameSystemKey={gameSystemKey} data={checks.data} />
    </>
  )
}

/**
 * The check being edited (a stored one by its key, or a new one) and the checks it was opened at.
 * The editor keeps that version until it is opened again: a refetch must not make a stale draft
 * look current. `opened` counts the openings, so each one starts the form again.
 */
type Selection = { original: string | null; start: GameSystemChecks; opened: number } | null

function CheckList({ gameSystemKey, data }: { gameSystemKey: string; data: GameSystemChecks }) {
  const move = useMoveCheck(gameSystemKey)
  const latest = useGameSystemChecks(gameSystemKey)
  const [selected, setSelected] = useState<Selection>(null)
  const [reloadFailed, setReloadFailed] = useState(false)
  const [conflict, setConflict] = useState<string | null>(null)

  function open(original: string | null, start: GameSystemChecks) {
    setConflict(null)
    setSelected((current) => ({ original, start, opened: (current?.opened ?? 0) + 1 }))
  }

  /**
   * Another request changed the draft: say so and reload, so the open editor starts again from
   * the latest check (or closes when it is gone) and its next save carries the new version.
   */
  function onConflict(message: string) {
    void reload().then(() => {
      setConflict(message)
    })
  }

  /** Refetches the checks and opens the editor again on them; a failure keeps what is shown. */
  async function reload() {
    const result = await latest.refetch()
    const fresh = result.data
    setReloadFailed(result.isError || fresh === undefined)
    if (result.isError || fresh === undefined) {
      return false
    }
    setSelected((current) => {
      if (current === null) {
        return null
      }
      // A check that is gone leaves nothing to edit.
      return current.original !== null && !fresh.checks.some((c) => c.key === current.original)
        ? null
        : { ...current, start: fresh, opened: current.opened + 1 }
    })
    return true
  }

  return (
    <section aria-label="Checks" className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="font-heading text-lg font-semibold">Checks</h2>
        <Button
          size="sm"
          onClick={() => {
            open(null, data)
          }}
        >
          New check
        </Button>
      </div>
      {move.error && (
        <p role="alert" className="text-sm text-destructive">
          {move.error.message}
        </p>
      )}
      {conflict !== null && (
        <p role="alert" className="text-sm text-destructive">
          {conflict} The latest version was loaded.
        </p>
      )}
      {reloadFailed && (
        <p role="alert" className="text-sm text-destructive">
          Couldn&apos;t reload the latest version. Try again.
        </p>
      )}
      {data.checks.length === 0 && (
        <p className="text-muted-foreground">This draft has no checks yet.</p>
      )}
      {data.checks.length > 0 && (
        <ul className="divide-y rounded-xl ring-1 ring-foreground/10">
          {data.checks.map((check, index) => (
            <CheckRow
              key={typeof check.key === 'string' ? check.key : `#${String(index)}`}
              gameSystemKey={gameSystemKey}
              data={data}
              check={check}
              index={index}
              selected={selected?.original === check.key}
              busy={move.isPending}
              onEdit={(original) => {
                setReloadFailed(false)
                open(original, data)
              }}
              onMove={(target) => {
                setReloadFailed(false)
                move.mutate(
                  { expectedVersion: data.version, check: check.key, index: target },
                  {
                    onError: (error) => {
                      if (error instanceof StudioError && error.status === 409) {
                        move.reset()
                        onConflict(error.message)
                      }
                    },
                  },
                )
              }}
              onDeleted={() => {
                if (selected?.original === check.key) {
                  setSelected(null)
                }
              }}
            />
          ))}
        </ul>
      )}
      {selected !== null && (
        <CheckEditor
          key={selected.opened}
          gameSystemKey={gameSystemKey}
          start={selected.start}
          original={selected.original}
          onSaved={(saved, key) => {
            open(key, saved)
          }}
          onConflict={onConflict}
        />
      )}
    </section>
  )
}

function CheckRow({
  gameSystemKey,
  data,
  check,
  index,
  selected,
  busy,
  onEdit,
  onMove,
  onDeleted,
}: {
  gameSystemKey: string
  data: GameSystemChecks
  check: GameSystemChecks['checks'][number]
  index: number
  selected: boolean
  busy: boolean
  onEdit: (key: string) => void
  onMove: (target: number) => void
  onDeleted: () => void
}) {
  const { key, label, dice } = check
  if (typeof key !== 'string') {
    return (
      <li className="px-4 py-3 text-sm text-muted-foreground">
        A check without a key can&apos;t be edited here.
      </li>
    )
  }

  const editable = readCheck(check) !== null

  return (
    <li
      aria-current={selected ? 'true' : undefined}
      className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 aria-[current=true]:bg-muted/50"
    >
      <p className="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-3 gap-y-1">
        <span className="font-medium">{typeof label === 'string' ? label : key}</span>
        <span className="font-mono text-sm break-all text-muted-foreground">{key}</span>
        <Badge variant="secondary">{typeof dice === 'string' ? dice : 'no dice'}</Badge>
        {!editable && <Badge variant="outline">Can&apos;t be edited here</Badge>}
      </p>
      <Button
        variant="outline"
        size="sm"
        aria-label={`Edit ${key}`}
        disabled={!editable}
        onClick={() => {
          onEdit(key)
        }}
      >
        Edit
      </Button>
      <Button
        variant="outline"
        size="sm"
        aria-label={`Move up ${key}`}
        disabled={index === 0 || busy}
        onClick={() => {
          onMove(index - 1)
        }}
      >
        Move up
      </Button>
      <Button
        variant="outline"
        size="sm"
        aria-label={`Move down ${key}`}
        disabled={index === data.checks.length - 1 || busy}
        onClick={() => {
          onMove(index + 1)
        }}
      >
        Move down
      </Button>
      <DeleteCheckDialog
        gameSystemKey={gameSystemKey}
        checkKey={key}
        version={data.version}
        onDeleted={onDeleted}
      />
    </li>
  )
}

/** Deleting is refused while a check step uses the check; the refusal lists where. */
function DeleteCheckDialog({
  gameSystemKey,
  checkKey,
  version,
  onDeleted,
}: {
  gameSystemKey: string
  checkKey: string
  version: number
  onDeleted: () => void
}) {
  const [open, setOpen] = useState(false)
  const remove = useDeleteCheck(gameSystemKey)
  const latest = useGameSystemChecks(gameSystemKey)
  const [reloadFailed, setReloadFailed] = useState(false)
  const reasonId = useId()

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        remove.reset()
        setReloadFailed(false)
      }}
    >
      <DialogTrigger asChild>
        <Button variant="outline" size="sm" aria-label={`Delete ${checkKey}`}>
          Delete
        </Button>
      </DialogTrigger>
      <DialogContent aria-describedby={reasonId}>
        <DialogHeader>
          <DialogTitle>Delete {checkKey}?</DialogTitle>
          <DialogDescription id={reasonId}>
            The check is removed from the draft. A check step that uses it keeps the draft from
            being deleted: the publisher lists where.
          </DialogDescription>
        </DialogHeader>
        {remove.error && (
          <p role="alert" className="text-sm text-destructive">
            {remove.error.message}
            {remove.error instanceof CheckError && remove.error.paths.length > 0
              ? ` Used by ${remove.error.paths.join(', ')}.`
              : ''}
          </p>
        )}
        {reloadFailed && (
          <p role="alert" className="text-sm text-destructive">
            Couldn&apos;t reload the latest version. Try again.
          </p>
        )}
        {remove.error instanceof StudioError && remove.error.status === 409 ? (
          <Button
            onClick={() => {
              void latest.refetch().then((result) => {
                setReloadFailed(result.isError)
                if (!result.isError) {
                  setOpen(false)
                }
              })
            }}
          >
            Reload the latest version
          </Button>
        ) : (
          <Button
            variant="destructive"
            disabled={remove.isPending}
            onClick={() => {
              remove.mutate(
                { expectedVersion: version, check: checkKey },
                {
                  onSuccess: () => {
                    setOpen(false)
                    onDeleted()
                  },
                },
              )
            }}
          >
            Delete the check
          </Button>
        )}
      </DialogContent>
    </Dialog>
  )
}

/** What a formula may read: the number, track and derived fields, a track's max, the trackers. */
function readableReferences(data: GameSystemChecks): ReferenceOption[] {
  return [
    ...data.fields.flatMap((field) => [
      { reference: field.key, label: field.label },
      ...(field.type === 'track'
        ? [{ reference: `${field.key}.max`, label: `${field.label} max` }]
        : []),
    ]),
    ...data.trackers.map((tracker) => ({
      reference: `tracker:${tracker.key}`,
      label: tracker.label,
    })),
  ]
}

/**
 * The form of one check, filled from the checks it was opened at, whose version a save carries:
 * after a conflict the next save still conflicts until the person reloads. Saving sends the whole
 * check; the bands are shown as stored, until the bands editor comes.
 */
function CheckEditor({
  gameSystemKey,
  start,
  original,
  onSaved,
  onConflict,
}: {
  gameSystemKey: string
  start: GameSystemChecks
  original: string | null
  onSaved: (saved: GameSystemChecks, key: string) => void
  onConflict: (message: string) => void
}) {
  const save = useSaveCheck(gameSystemKey)
  const [draft, setDraft] = useState<CheckDraft>(() => {
    const stored = start.checks.find((check) => check.key === original)
    return (stored && readCheck(stored)) ?? blankCheck()
  })
  const [errors, setErrors] = useState<CheckErrors>({})
  const { error } = save
  const references = useMemo(() => readableReferences(start), [start])
  const difficultyId = useId()

  const position =
    original === null
      ? start.checks.length
      : start.checks.findIndex((check) => check.key === original)
  const answer =
    error instanceof CheckError && error.status === 422
      ? checkAnswer(error.message, position)
      : null
  const message =
    error === null
      ? ''
      : `${answer ? (answer.form ?? '') : error.message}${
          error instanceof CheckError && error.paths.length > 0
            ? ` Used by ${error.paths.join(', ')}.`
            : ''
        }`
  const shown: CheckErrors = { ...errors, ...answer?.fields }

  const change = (changes: Partial<CheckDraft>) => {
    setDraft((current) => ({ ...current, ...changes }))
  }
  const changeDifficulty = (changes: Partial<DifficultyDraft>) => {
    setDraft((current) =>
      current.difficulty === null
        ? current
        : { ...current, difficulty: { ...current.difficulty, ...changes } },
    )
  }

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const result = writeCheck(draft)
    if ('errors' in result) {
      setErrors(result.errors)
      return
    }
    setErrors({})
    save.mutate(
      { expectedVersion: start.version, original, check: result.check },
      {
        onSuccess: (saved) => {
          // The returned read model says which check was saved.
          onSaved(
            saved,
            saved.checks.find((check) => check.key === result.check.key)?.key ??
              String(result.check.key),
          )
        },
        onError: (failure) => {
          if (failure instanceof StudioError && failure.status === 409) {
            onConflict(failure.message)
          }
        },
      },
    )
  }

  const title = original === null ? 'New check' : `Edit ${original}`

  return (
    <form
      aria-label={title}
      className="space-y-4 rounded-xl p-4 ring-1 ring-foreground/10"
      onSubmit={handleSubmit}
    >
      <h3 className="font-heading font-semibold">{title}</h3>
      <TextField
        label="Key"
        value={draft.key}
        onChange={(key) => {
          change({ key })
        }}
        error={shown.key}
      />
      <TextField
        label="Label"
        value={draft.label}
        onChange={(label) => {
          change({ label })
        }}
        error={shown.label}
      />
      <TextField
        label="Hint"
        value={draft.hint}
        onChange={(hint) => {
          change({ hint })
        }}
        error={shown.hint}
      />
      <TextField
        label="Dice"
        value={draft.dice}
        onChange={(dice) => {
          change({ dice })
        }}
        error={shown.dice}
        help="Dice notation such as 1d10 or 2d6; the publisher checks it when you save."
      />
      <FormulaInput
        label="Modifier"
        value={draft.modifier}
        onChange={(modifier) => {
          change({ modifier })
        }}
        diagnostics={null}
        references={references}
        error={shown.modifier}
      />
      <div className="space-y-3">
        <div className="flex items-center gap-2">
          <input
            id={difficultyId}
            type="checkbox"
            checked={draft.difficulty !== null}
            onChange={(event) => {
              change({ difficulty: event.target.checked ? blankDifficulty() : null })
            }}
          />
          <Label htmlFor={difficultyId}>Difficulty</Label>
        </div>
        {shown.difficulty && <p className="text-sm text-destructive">{shown.difficulty}</p>}
        {draft.difficulty !== null && (
          <div className="grid gap-3 sm:grid-cols-2">
            <TextField
              label="Difficulty label"
              value={draft.difficulty.label}
              onChange={(label) => {
                changeDifficulty({ label })
              }}
              error={shown.difficultyLabel}
            />
            <TextField
              label="Default"
              value={draft.difficulty.default}
              onChange={(value) => {
                changeDifficulty({ default: value })
              }}
              error={shown.difficultyDefault}
            />
            <TextField
              label="Min"
              value={draft.difficulty.min}
              onChange={(min) => {
                changeDifficulty({ min })
              }}
              error={shown.difficultyMin}
            />
            <TextField
              label="Max"
              value={draft.difficulty.max}
              onChange={(max) => {
                changeDifficulty({ max })
              }}
              error={shown.difficultyMax}
            />
          </div>
        )}
      </div>
      <Bands bands={draft.bands} />
      {message !== '' && (
        <p role="alert" className="text-sm text-destructive">
          {message}
        </p>
      )}
      <div className="flex flex-wrap gap-2">
        <Button type="submit" disabled={save.isPending}>
          {original === null ? 'Create check' : 'Save check'}
        </Button>
      </div>
    </form>
  )
}

/** The bands as stored; they are kept as they are until the bands editor comes. */
function Bands({ bands }: { bands: unknown[] }) {
  return (
    <div className="space-y-2">
      <h4 className="text-sm font-medium">Bands</h4>
      <ul className="divide-y rounded-lg ring-1 ring-foreground/10">
        {describeBands(bands).map((band, index) => (
          // A band key can repeat in a draft that breaks the rules, so the position is part of the key.
          <li
            key={`${String(index)}:${band.key}`}
            className="flex flex-wrap gap-x-3 px-3 py-2 text-sm"
          >
            <span className="font-medium">{band.label}</span>
            <span className="font-mono break-all text-muted-foreground">{band.key}</span>
            <span className="text-muted-foreground">{band.bound}</span>
          </li>
        ))}
      </ul>
      <p className="text-xs text-muted-foreground">
        Bands are kept as they are; editing them comes later.
      </p>
    </div>
  )
}
