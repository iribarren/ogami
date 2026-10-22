import { ArrowDown, ArrowUp, Pencil, Plus, Trash2 } from 'lucide-react'
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
import { DieShape } from '@/shared/ui/die-shape'
import { IconButton } from '@/shared/ui/icon-button'
import { IconTile } from '@/shared/ui/icon-tile'
import { Label } from '@/shared/ui/label'

import type { SampleValues } from '../sheet/useSheetSamples'
import { BandsEditor } from './BandsEditor'
import { CheckSandbox } from './CheckSandbox'
import { TextField } from '../narrative/TextField'
import { FormulaInput } from '../sheet/FormulaInput'
import { StudioError } from '../useGameSystems'
import {
  blankCheck,
  blankDifficulty,
  followBands,
  mergeBandErrors,
  readCheck,
  sameCheck,
  writeCheck,
  type BandErrorMap,
  type CheckDraft,
  type CheckErrors,
  type DifficultyDraft,
  type StoredCheck,
} from './checkDraft'
import { checkFormulaDiagnostics, checkReferences } from './checkFormulaDiagnostics'
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
export function ChecksTab({
  gameSystemKey,
  sampleValues,
}: {
  gameSystemKey: string
  /** The sample values the page keeps, shared with the Sheet tab's preview. */
  sampleValues: SampleValues
}) {
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
      <CheckList gameSystemKey={gameSystemKey} data={checks.data} sampleValues={sampleValues} />
    </>
  )
}

/**
 * The check being edited (a stored one by its key, or a new one) and the checks it was opened at.
 * The editor keeps that version until it is opened again: a refetch must not make a stale draft
 * look current. `opened` counts the openings, so each one starts the form again.
 */
type Selection = { original: string | null; start: GameSystemChecks; opened: number } | null

function CheckList({
  gameSystemKey,
  data,
  sampleValues,
}: {
  gameSystemKey: string
  data: GameSystemChecks
  sampleValues: SampleValues
}) {
  const move = useMoveCheck(gameSystemKey)
  const latest = useGameSystemChecks(gameSystemKey)
  const [selected, setSelected] = useState<Selection>(null)
  const [conflict, setConflict] = useState<{ message: string; loaded: boolean } | null>(null)

  function open(original: string | null, start: GameSystemChecks) {
    setConflict(null)
    setSelected((current) => ({ original, start, opened: (current?.opened ?? 0) + 1 }))
  }

  /**
   * Another request changed the draft: say so and reload, so the open editor starts again from
   * the latest check (or closes when it is gone) and its next save carries the new version.
   */
  function onConflict(message: string) {
    void reload().then((loaded) => {
      setConflict({ message, loaded })
    })
  }

  /**
   * A move or delete made from this list succeeded: the open editor moves on to the version it
   * returned, keeping its unsaved edits, so the person's own change does not make their next save
   * a conflict. Only when nothing else changed the draft since the editor was opened (the version
   * went up by exactly this one command) and the edited check is still there; otherwise the old
   * version stays and a save reports the real conflict.
   */
  function advance(result: GameSystemChecks) {
    setSelected((current) => {
      if (
        current === null ||
        result.version !== current.start.version + 1 ||
        (current.original !== null && !result.checks.some((c) => c.key === current.original))
      ) {
        return current
      }
      return { ...current, start: result }
    })
  }

  /**
   * The open editor saved its check: it goes on from the returned checks, keeping the form as it
   * is (and the sandbox with its results), under the key the check now has. Only the editor that
   * was open when the save began: another opening has its own check.
   */
  function adopt(opened: number, saved: GameSystemChecks, key: string) {
    setConflict(null)
    setSelected((current) =>
      current !== null && current.opened === opened
        ? { ...current, original: key, start: saved }
        : current,
    )
  }

  /** Refetches the checks and opens the editor again on them; a failure keeps what is shown. */
  async function reload() {
    const result = await latest.refetch()
    const fresh = result.data
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
      {move.error && (
        <p role="alert" className="text-sm text-destructive">
          {move.error.message}
        </p>
      )}
      {conflict !== null && (
        <div role="alert" className="flex flex-wrap items-center gap-3 text-sm text-destructive">
          <p>
            {conflict.message}{' '}
            {conflict.loaded
              ? 'The latest version was loaded.'
              : "Couldn't load the latest version: what is shown may be out of date."}
          </p>
          {!conflict.loaded && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                void reload().then((loaded) => {
                  if (loaded) {
                    setConflict((current) => current && { ...current, loaded })
                  }
                })
              }}
            >
              Try again
            </Button>
          )}
        </div>
      )}
      <div className="panel">
        <div className="panel-head">
          <IconTile size="sm">
            <DieShape sides={20} inner />
          </IconTile>
          <h2 className="font-heading text-panel-title">Checks</h2>
          <Button
            className="ml-auto"
            variant="outline"
            size="sm"
            onClick={() => {
              open(null, data)
            }}
          >
            <Plus aria-hidden="true" />
            New check
          </Button>
        </div>
        {data.checks.length === 0 && (
          <div className="panel-body">
            <div className="empty-state">
              <IconTile size="sm">
                <DieShape sides={20} inner />
              </IconTile>
              <p>This draft has no checks yet.</p>
              <p className="hint">Use New check to add one.</p>
            </div>
          </div>
        )}
        {data.checks.length > 0 && (
          <ul>
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
                  open(original, data)
                }}
                onMove={(target) => {
                  move.mutate(
                    { expectedVersion: data.version, check: check.key, index: target },
                    {
                      onSuccess: advance,
                      onError: (error) => {
                        if (error instanceof StudioError && error.status === 409) {
                          move.reset()
                          onConflict(error.message)
                        }
                      },
                    },
                  )
                }}
                onDeleted={(result) => {
                  if (selected?.original === check.key) {
                    setSelected(null)
                  } else {
                    advance(result)
                  }
                }}
              />
            ))}
          </ul>
        )}
      </div>
      {selected !== null && (
        <CheckEditor
          key={selected.opened}
          gameSystemKey={gameSystemKey}
          start={selected.start}
          original={selected.original}
          sampleValues={sampleValues}
          onSaved={(saved, key) => {
            adopt(selected.opened, saved, key)
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
  onDeleted: (result: GameSystemChecks) => void
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
    <li aria-current={selected ? 'true' : undefined} className="list-row">
      <p className="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-2 gap-y-1">
        <span className="list-row-title">{typeof label === 'string' ? label : key}</span>
        <span className="list-row-key break-all">{key}</span>
        <Badge variant="secondary">{typeof dice === 'string' ? dice : 'no dice'}</Badge>
        {!editable && <Badge variant="outline">Can&apos;t be edited here</Badge>}
      </p>
      <IconButton
        label={`Edit ${key}`}
        dense
        disabled={!editable}
        onClick={() => {
          onEdit(key)
        }}
      >
        <Pencil />
      </IconButton>
      <IconButton
        label={`Move up ${key}`}
        dense
        disabled={index === 0 || busy}
        onClick={() => {
          onMove(index - 1)
        }}
      >
        <ArrowUp />
      </IconButton>
      <IconButton
        label={`Move down ${key}`}
        dense
        disabled={index === data.checks.length - 1 || busy}
        onClick={() => {
          onMove(index + 1)
        }}
      >
        <ArrowDown />
      </IconButton>
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
  onDeleted: (result: GameSystemChecks) => void
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
        <IconButton label={`Delete ${checkKey}`} dense destructive>
          <Trash2 />
        </IconButton>
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
                  onSuccess: (result) => {
                    setOpen(false)
                    onDeleted(result)
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

/**
 * The form of one check, filled from the checks it was opened at, whose version a save carries:
 * after a conflict the next save still conflicts until the person reloads. Saving sends the whole
 * check, bands included.
 */
function CheckEditor({
  gameSystemKey,
  start,
  original,
  sampleValues,
  onSaved,
  onConflict,
}: {
  gameSystemKey: string
  start: GameSystemChecks
  original: string | null
  sampleValues: SampleValues
  onSaved: (saved: GameSystemChecks, key: string) => void
  onConflict: (message: string) => void
}) {
  const save = useSaveCheck(gameSystemKey)
  const [draft, setDraft] = useState<CheckDraft>(() => {
    const stored = start.checks.find((check) => check.key === original)
    return (stored && readCheck(stored)) ?? blankCheck()
  })
  const [errors, setErrors] = useState<Omit<CheckErrors, 'band'>>({})
  // The band messages, by position, from the form's own checks and from the publisher's refusal.
  // They follow their band when the bands are rearranged: a message must not stay on the band
  // that took the place of the one it was about.
  const [localBands, setLocalBands] = useState<BandErrorMap>({})
  const [publishedBands, setPublishedBands] = useState<BandErrorMap>({})
  const { error } = save
  const references = useMemo(() => checkReferences(start, 'modifier', false), [start])
  const modifierDiagnostics = useMemo(
    () =>
      draft.modifier.trim() === ''
        ? null
        : checkFormulaDiagnostics({
            formula: draft.modifier,
            scope: 'modifier',
            catalog: start,
            difficulty: null,
          }),
    [draft.modifier, start],
  )
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
  const shown: CheckErrors = {
    ...errors,
    ...answer?.fields,
    // Per input: a refusal of a band's key does not hide the form's message about its label.
    band: mergeBandErrors(localBands, publishedBands),
  }

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

  /** Shows what is wrong with the form, as a refused submit does. */
  function showErrors(found: CheckErrors) {
    const { band, ...fields } = found
    setErrors(fields)
    setLocalBands(band ?? {})
  }

  /**
   * Saves the check and goes on from what the API returned: the saved check's key, or null when
   * the save was refused (a 422 shows on the form, a 409 reloads the editor).
   */
  async function commit(check: StoredCheck): Promise<string | null> {
    setErrors({})
    setLocalBands({})
    setPublishedBands({})
    try {
      const saved = await save.mutateAsync({ expectedVersion: start.version, original, check })
      // The returned read model says which check was saved.
      const key = saved.checks.find((held) => held.key === check.key)?.key ?? String(check.key)
      onSaved(saved, key)
      return key
    } catch (failure) {
      if (failure instanceof StudioError && failure.status === 409) {
        onConflict(failure.message)
      } else if (failure instanceof CheckError && failure.status === 422) {
        setPublishedBands(checkAnswer(failure.message, position).fields.band ?? {})
      }
      return null
    }
  }

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const result = writeCheck(draft)
    if ('errors' in result) {
      showErrors(result.errors)
      return
    }
    void commit(result.check)
  }

  // Whether the form says something other than the saved check does.
  const stored = start.checks.find((check) => check.key === original)
  const written = writeCheck(draft)
  const dirty = !('check' in written) || stored === undefined || !sameCheck(written.check, stored)

  const title = original === null ? 'New check' : `Edit ${original}`

  return (
    <div className="space-y-4">
      <form
        aria-label={title}
        className="space-y-4 rounded-xl p-4 ring-1 ring-foreground/10"
        onSubmit={handleSubmit}
      >
        <h3 className="font-heading font-semibold">{title}</h3>
        <TextField
          label="Key"
          keyField
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
          diagnostics={modifierDiagnostics}
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
        <BandsEditor
          bands={draft.bands}
          onChange={(bands, order) => {
            change({ bands })
            if (order !== undefined) {
              setLocalBands((held) => followBands(held, order))
              setPublishedBands((held) => followBands(held, order))
            }
          }}
          catalog={start}
          difficulty={draft.difficulty}
          error={shown.bands}
          errors={shown.band ?? {}}
        />
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
      {original !== null && (
        <CheckSandbox
          gameSystemKey={gameSystemKey}
          catalog={start}
          bands={draft.bands}
          difficulty={draft.difficulty}
          sampleValues={sampleValues}
          dirty={dirty}
          ensureSaved={async () => {
            if (!dirty) {
              return original
            }
            const result = writeCheck(draft)
            if ('errors' in result) {
              showErrors(result.errors)
              return null
            }
            return commit(result.check)
          }}
        />
      )}
    </div>
  )
}
