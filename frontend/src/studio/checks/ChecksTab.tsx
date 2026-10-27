import { ArrowDown, ArrowUp, Copy, Pencil, Plus, Trash2 } from 'lucide-react'
import { useCallback, useId, useMemo, useState, type ReactNode, type SubmitEvent } from 'react'

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
import { BandsEditor, type BandSource } from './BandsEditor'
import { CheckSandbox } from './CheckSandbox'
import { PushEditor } from './PushEditor'
import { TextField } from '../narrative/TextField'
import { FormulaInput } from '../sheet/FormulaInput'
import { previewTemplate } from '../sheet/previewTemplate'
import { useGameSystemSheet } from '../sheet/useGameSystemSheet'
import { StudioError } from '../useGameSystems'
import {
  blankCheck,
  blankDifficulty,
  duplicateCheck,
  followBands,
  mergeBandErrors,
  modeOf,
  readCheck,
  sameCheck,
  switchMode,
  writeCheck,
  type BandDraft,
  type BandErrorMap,
  type CheckDraft,
  type CheckErrors,
  type CheckMode,
  type DifficultyDraft,
  type PoolDraft,
  type PushDraft,
  type StoredCheck,
} from './checkDraft'
import { checkFormulaDiagnostics, checkReads, checkReferences } from './checkFormulaDiagnostics'
import { checkAnswer } from './checkError'
import { pushContext } from './pushContext'
import { sampleDefaults } from './checkSandbox'
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
 * look current. `opened` counts the openings, so each one starts the form again. A new check may
 * come with a `prefill` (a duplicate): the form starts from it, unsaved.
 */
type Selection = {
  original: string | null
  start: GameSystemChecks
  opened: number
  prefill: CheckDraft | null
} | null

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

  function open(
    original: string | null,
    start: GameSystemChecks,
    prefill: CheckDraft | null = null,
  ) {
    setConflict(null)
    setSelected((current) => ({ original, start, prefill, opened: (current?.opened ?? 0) + 1 }))
  }

  /** Opens a new, unsaved check prefilled from the stored one `key`; nothing is sent until saved. */
  function duplicate(key: string) {
    const stored = data.checks.find((check) => check.key === key)
    const source = stored && readCheck(stored)
    if (source) {
      open(
        null,
        data,
        duplicateCheck(
          source,
          data.checks.flatMap((check) => (typeof check.key === 'string' ? [check.key] : [])),
        ),
      )
    }
  }

  /** Opens a new, unsaved check prefilled from what the editor shows, unsaved edits included. */
  function duplicateDraft(draft: CheckDraft) {
    open(
      null,
      data,
      duplicateCheck(
        draft,
        data.checks.flatMap((check) => (typeof check.key === 'string' ? [check.key] : [])),
      ),
    )
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
      <div className="flex flex-wrap items-start gap-4">
        <div className="max-w-[330px] flex-[1_1_280px] panel">
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
                  onDuplicate={duplicate}
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
          <div className="min-w-0 flex-[2_1_440px]">
            <CheckEditor
              key={selected.opened}
              gameSystemKey={gameSystemKey}
              start={selected.start}
              original={selected.original}
              prefill={selected.prefill}
              sampleValues={sampleValues}
              onSaved={(saved, key) => {
                adopt(selected.opened, saved, key)
              }}
              onConflict={onConflict}
              onDuplicate={duplicateDraft}
              deleteAction={
                selected.original !== null && (
                  <DeleteCheckDialog
                    gameSystemKey={gameSystemKey}
                    checkKey={selected.original}
                    version={data.version}
                    onDeleted={() => {
                      setSelected(null)
                    }}
                    text
                  />
                )
              }
            />
          </div>
        )}
      </div>
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
  onDuplicate,
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
  onDuplicate: (key: string) => void
  onMove: (target: number) => void
  onDeleted: (result: GameSystemChecks) => void
}) {
  const { key, label, dice, pool } = check
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
      <div className="min-w-0 flex-1 space-y-1">
        <p className="list-row-title">{typeof label === 'string' ? label : key}</p>
        <p className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
          <span className="list-row-key break-all">{key}</span>
          <Badge variant="secondary">
            {typeof dice === 'string' ? dice : pool === undefined ? 'no dice' : 'dice pool'}
          </Badge>
          {!editable && <Badge variant="outline">Can&apos;t be edited here</Badge>}
        </p>
      </div>
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
        label={`Duplicate ${key}`}
        dense
        disabled={!editable}
        onClick={() => {
          onDuplicate(key)
        }}
      >
        <Copy />
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
  text = false,
}: {
  gameSystemKey: string
  checkKey: string
  version: number
  onDeleted: (result: GameSystemChecks) => void
  /** A "Delete" button with text (the editor's head) instead of the list row's icon button. */
  text?: boolean
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
        {text ? (
          <Button variant="outline" size="sm">
            <Trash2 aria-hidden="true" />
            Delete
          </Button>
        ) : (
          <IconButton label={`Delete ${checkKey}`} dense destructive>
            <Trash2 />
          </IconButton>
        )}
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
  prefill,
  sampleValues,
  onSaved,
  onConflict,
  onDuplicate,
  deleteAction,
}: {
  gameSystemKey: string
  start: GameSystemChecks
  original: string | null
  /** A duplicate's starting form; only a new check uses it. */
  prefill: CheckDraft | null
  sampleValues: SampleValues
  onSaved: (saved: GameSystemChecks, key: string) => void
  onConflict: (message: string) => void
  /** The editor head's Duplicate: copies the draft as shown, not the stored check. */
  onDuplicate: (draft: CheckDraft) => void
  /** The editor head's Delete, which needs the list's latest version. */
  deleteAction: ReactNode
}) {
  const save = useSaveCheck(gameSystemKey)
  const [draft, setDraft] = useState<CheckDraft>(() => {
    if (original === null) {
      return prefill ?? blankCheck()
    }
    const stored = start.checks.find((check) => check.key === original)
    return (stored && readCheck(stored)) ?? blankCheck()
  })
  // The other checks whose bands "Copy from a check…" offers: the ones the form can read.
  const copyFrom = useMemo<BandSource[]>(
    () =>
      start.checks.flatMap((check) => {
        const read = check.key === original ? null : readCheck(check)
        return read ? [{ key: read.key, label: read.label, bands: read.bands }] : []
      }),
    [start, original],
  )
  const [errors, setErrors] = useState<Omit<CheckErrors, 'band'>>({})
  // The band messages, by position, from the form's own checks and from the publisher's refusal.
  // They follow their band when the bands are rearranged: a message must not stay on the band
  // that took the place of the one it was about.
  const [localBands, setLocalBands] = useState<BandErrorMap>({})
  const [publishedBands, setPublishedBands] = useState<BandErrorMap>({})
  const { error } = save
  // The previews read a number field at the value the test roll starts it at.
  const sheet = useGameSystemSheet(gameSystemKey)
  const catalog = useMemo(
    () => ({
      ...start,
      defaults:
        sheet.data === undefined ? {} : sampleDefaults(previewTemplate(sheet.data, null).fields),
    }),
    [start, sheet.data],
  )
  const references = useMemo(() => checkReferences(start, 'modifier', false), [start])
  const modifierDiagnostics = useMemo(
    () =>
      draft.modifier.trim() === ''
        ? null
        : checkFormulaDiagnostics({
            formula: draft.modifier,
            scope: 'modifier',
            catalog,
            difficulty: null,
          }),
    [draft.modifier, catalog],
  )
  const reads = useMemo(() => checkReads(draft.modifier, start), [draft.modifier, start])
  // The pool's count is a number or a formula over the sheet, read as a modifier is.
  const count = draft.pool?.count.trim() ?? ''
  const countIsFormula = count !== '' && !/^-?\d+$/.test(count)
  const countDiagnostics = useMemo(
    () =>
      countIsFormula
        ? checkFormulaDiagnostics({ formula: count, scope: 'modifier', catalog, difficulty: null })
        : null,
    [countIsFormula, count, catalog],
  )
  const countReads = useMemo(
    () => (countIsFormula ? checkReads(count, start) : []),
    [countIsFormula, count, start],
  )
  // The Hunger count is read the same way.
  const hunger = draft.pool?.hunger?.trim() ?? ''
  const hungerIsFormula = hunger !== '' && !/^-?\d+$/.test(hunger)
  const hungerDiagnostics = useMemo(
    () =>
      hungerIsFormula
        ? checkFormulaDiagnostics({ formula: hunger, scope: 'modifier', catalog, difficulty: null })
        : null,
    [hungerIsFormula, hunger, catalog],
  )
  const hungerReads = useMemo(
    () => (hungerIsFormula ? checkReads(hunger, start) : []),
    [hungerIsFormula, hunger, start],
  )
  const costContext = useMemo(() => pushContext(start), [start])
  const modeId = useId()
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
  // The same object until a band message changes, so the band rows can skip renders.
  const bandErrors = useMemo(
    () => mergeBandErrors(localBands, publishedBands),
    [localBands, publishedBands],
  )
  const shown: CheckErrors = {
    ...errors,
    ...answer?.fields,
    // Per input: a refusal of a band's key does not hide the form's message about its label.
    band: bandErrors,
  }

  const change = (changes: Partial<CheckDraft>) => {
    setDraft((current) => ({ ...current, ...changes }))
  }
  const changePool = (changes: Partial<PoolDraft>) => {
    setDraft((current) =>
      current.pool === null ? current : { ...current, pool: { ...current.pool, ...changes } },
    )
  }
  const changePush = (push: PushDraft | null) => {
    setDraft((current) => (current.pool === null ? current : { ...current, push }))
  }
  const changeMode = (mode: CheckMode) => {
    setDraft((current) => switchMode(current, mode))
  }
  const changeBands = useCallback((bands: BandDraft[], order?: (number | null)[]) => {
    setDraft((current) => ({ ...current, bands }))
    if (order !== undefined) {
      setLocalBands((held) => followBands(held, order))
      setPublishedBands((held) => followBands(held, order))
    }
  }, [])
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

  // The form is named by the key (what the list's buttons say); the heading shows the label.
  const title = original === null ? 'New check' : `Edit ${original}`
  const heading =
    original !== null && typeof stored?.label === 'string' && stored.label.trim() !== ''
      ? `Edit ${stored.label}`
      : title

  return (
    <div className="space-y-4">
      <form aria-label={title} className="panel" onSubmit={handleSubmit}>
        <div className="panel-head">
          <IconTile size="lg">
            <DieShape sides={20} inner />
          </IconTile>
          <h3 className="font-heading text-lg font-semibold">{heading}</h3>
          {original !== null && (
            <div className="ml-auto flex gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => {
                  onDuplicate(draft)
                }}
              >
                <Copy aria-hidden="true" />
                Duplicate
              </Button>
              {deleteAction}
            </div>
          )}
        </div>
        <div className="space-y-4 panel-body">
          <div className="grid gap-3 sm:grid-cols-2">
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
            <div className="sm:col-span-2">
              <TextField
                label="Hint"
                value={draft.hint}
                onChange={(hint) => {
                  change({ hint })
                }}
                error={shown.hint}
              />
            </div>
            <fieldset className="space-y-2 sm:col-span-2" aria-describedby={modeId}>
              <legend className="text-sm font-medium">Roll type</legend>
              <div className="flex flex-wrap gap-4">
                {(
                  [
                    ['dice', 'Dice roll'],
                    ['pool', 'Dice pool'],
                  ] as const
                ).map(([mode, name]) => (
                  <Label key={mode} className="flex items-center gap-2 font-normal">
                    <input
                      type="radio"
                      name={`${modeId}-mode`}
                      checked={modeOf(draft) === mode}
                      onChange={() => {
                        changeMode(mode)
                      }}
                    />
                    {name}
                  </Label>
                ))}
              </div>
              <p id={modeId} className="hint">
                A dice roll adds a modifier to its total; a dice pool counts the dice that succeed.
                A push goes with the pool: switching to Dice roll drops both.
              </p>
            </fieldset>
            {draft.pool === null ? (
              <>
                <TextField
                  label="Dice"
                  value={draft.dice}
                  onChange={(dice) => {
                    change({ dice })
                  }}
                  error={shown.dice}
                  help="Dice notation such as 1d10 or 2d6; the publisher checks it when you save."
                />
                <div className="space-y-2">
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
                  {reads.length > 0 && <p className="hint">Reads {reads.join(', ')}</p>}
                </div>
              </>
            ) : (
              <>
                <div className="space-y-2 sm:col-span-2">
                  <FormulaInput
                    label="Pool count"
                    value={draft.pool.count}
                    onChange={(value) => {
                      changePool({ count: value })
                    }}
                    diagnostics={countDiagnostics}
                    references={references}
                    error={shown.poolCount}
                  />
                  <p className="hint">
                    A number from 1 to 100, or a formula over the sheet such as {'{agility}'} + 1.
                  </p>
                  {countReads.length > 0 && <p className="hint">Reads {countReads.join(', ')}</p>}
                </div>
                <TextField
                  label="Pool sides"
                  value={draft.pool.sides}
                  onChange={(sides) => {
                    changePool({ sides })
                  }}
                  error={shown.poolSides}
                />
                <TextField
                  label="Success at"
                  value={draft.pool.successAt}
                  onChange={(successAt) => {
                    changePool({ successAt })
                  }}
                  error={shown.poolSuccessAt}
                  help="A die succeeds on this face or higher."
                />
                <TextField
                  label="Critical pairs bonus"
                  value={draft.pool.criticalsBonus ?? ''}
                  onChange={(criticalsBonus) => {
                    changePool({ criticalsBonus })
                  }}
                  error={shown.poolCriticalsBonus}
                  help="Optional, 1 to 10. Each pair of dice showing the top face adds this many successes."
                />
                <div className="space-y-2">
                  <FormulaInput
                    label="Hunger dice"
                    value={draft.pool.hunger ?? ''}
                    onChange={(value) => {
                      changePool({ hunger: value })
                    }}
                    diagnostics={hungerDiagnostics}
                    references={references}
                    error={shown.poolHunger}
                  />
                  <p className="hint">
                    Optional: a number from 0 to 100, or a formula over the sheet. Hunger dice are
                    part of the pool and are never re-rolled by a push.
                  </p>
                  {hungerReads.length > 0 && <p className="hint">Reads {hungerReads.join(', ')}</p>}
                </div>
              </>
            )}
          </div>
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
          {draft.pool !== null && (
            <PushEditor
              push={draft.push}
              context={costContext}
              labelError={shown.pushLabel}
              maxDiceError={shown.pushMaxDice}
              effectErrors={shown.pushEffects ?? []}
              onChange={changePush}
              onRestructure={() => {
                setErrors((current) => ({ ...current, pushEffects: undefined }))
              }}
            />
          )}
          <BandsEditor
            bands={draft.bands}
            onChange={changeBands}
            catalog={catalog}
            difficulty={draft.difficulty}
            copyFrom={copyFrom}
            error={shown.bands}
            errors={bandErrors}
          />
          {message !== '' && (
            <p role="alert" className="text-sm text-destructive">
              {message}
            </p>
          )}
        </div>
        <div className="action-bar">
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
          pool={draft.pool}
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
