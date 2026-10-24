import { memo, useCallback, useEffect, useId, useState, type SubmitEvent } from 'react'

import { useStableItems } from '@/shared/lib/useStableItems'
import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/shared/ui/dialog'

import { TextField } from '../narrative/TextField'
import { StudioError } from '../useGameSystems'
import {
  addGroup,
  fieldsHeldBy,
  maxGroupColumns,
  maxSheetColumns,
  moveGroup,
  readLayout,
  removeGroup,
  writeLayout,
  type GroupDraft,
  type LayoutDraft,
  type LayoutErrors,
} from './layoutDraft'
import { SelectField } from './SheetFieldDialog'
import { sheetLayoutAnswer } from './sheetError'
import {
  SheetError,
  useDeclareSheetLayout,
  useGameSystemSheet,
  useRemoveSheetLayout,
  useSaveSheetLayout,
  type GameSystemSheet,
} from './useGameSystemSheet'

const numbered = (count: number) =>
  Array.from({ length: count }, (_, at) => ({ value: String(at + 1), label: String(at + 1) }))

const ignoreDraft = () => undefined

const reloadFailed = "Couldn't reload the latest version. Try again."

/** What a refusal says, with the places that read the thing when the API names them. */
function refusal(error: Error) {
  return error instanceof SheetError && error.paths.length > 0
    ? `${error.message} Used by ${error.paths.join(', ')}.`
    : error.message
}

/**
 * The sheet's layout: declare it from the fields' groups, or edit and remove the declared one.
 * Fields keep their own group and order either way. While the edit dialog is open, `onDraftChange`
 * is given its unsaved draft (null once it closes), for the preview to show.
 */
export function SheetLayoutPanel({
  gameSystemKey,
  sheet,
  onDraftChange = ignoreDraft,
}: {
  gameSystemKey: string
  sheet: GameSystemSheet
  onDraftChange?: (draft: LayoutDraft | null) => void
}) {
  const declare = useDeclareSheetLayout(gameSystemKey)

  return (
    <section aria-label="Layout" className="space-y-2 border-b bg-secondary/40 px-4 py-2.5 text-sm">
      {sheet.groups === null ? (
        <>
          <p className="font-medium">Layout: none</p>
          <p className="hint">
            This sheet has no layout: fields render in one column, grouped as their group text
            reads. Declaring a layout makes a group of each one in order of first appearance, and
            puts fields without a group in &quot;General&quot;.
          </p>
          {declare.error && (
            <div
              role="alert"
              className="flex flex-wrap items-center gap-3 text-sm text-destructive"
            >
              <p>{declare.error.message}</p>
              {declare.error instanceof StudioError && declare.error.status === 409 && (
                <ReloadLatest gameSystemKey={gameSystemKey} onLoaded={declare.reset} />
              )}
            </div>
          )}
          {sheet.fields.length === 0 && (
            <p className="text-sm text-muted-foreground">Add a field first.</p>
          )}
          <Button
            size="sm"
            disabled={declare.isPending || sheet.fields.length === 0}
            onClick={() => {
              declare.mutate({ expectedVersion: sheet.version })
            }}
          >
            Declare layout
          </Button>
        </>
      ) : (
        <>
          <p className="font-medium">
            Layout: {sheet.columns === 1 ? '1 column' : `${String(sheet.columns)} columns`},{' '}
            {sheet.groups.length === 1 ? '1 group' : `${String(sheet.groups.length)} groups`}
          </p>
          {readLayout(sheet) === null && (
            <p className="text-sm text-destructive">
              The layout holds a value this editor cannot show. Remove it to declare it again.
            </p>
          )}
          <div className="flex flex-wrap gap-2">
            <EditLayoutDialog
              gameSystemKey={gameSystemKey}
              sheet={sheet}
              editable={readLayout(sheet) !== null}
              onDraftChange={onDraftChange}
            />
            <RemoveLayoutDialog gameSystemKey={gameSystemKey} version={sheet.version} />
          </div>
        </>
      )}
    </section>
  )
}

/** Refetches the sheet; calls `onLoaded` once it is the latest. A failed reload says so. */
export function ReloadLatest({
  gameSystemKey,
  onLoaded,
}: {
  gameSystemKey: string
  onLoaded: () => void
}) {
  const latest = useGameSystemSheet(gameSystemKey)
  const [failed, setFailed] = useState(false)

  return (
    <>
      {failed && (
        <p role="alert" className="text-sm text-destructive">
          {reloadFailed}
        </p>
      )}
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={() => {
          void latest.refetch().then((result) => {
            setFailed(result.isError)
            if (!result.isError) {
              onLoaded()
            }
          })
        }}
      >
        Reload the latest version
      </Button>
    </>
  )
}

/** Removing drops columns, groups and spans, so it asks first. */
function RemoveLayoutDialog({
  gameSystemKey,
  version,
}: {
  gameSystemKey: string
  version: number
}) {
  const [open, setOpen] = useState(false)
  const remove = useRemoveSheetLayout(gameSystemKey)

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        remove.reset()
      }}
    >
      <DialogTrigger asChild>
        <Button variant="outline" size="sm">
          Remove layout
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Remove the layout?</DialogTitle>
          <DialogDescription>
            This drops the sheet&apos;s columns, the declared groups and every field&apos;s span.
            Fields keep their group and order.
          </DialogDescription>
        </DialogHeader>
        {remove.error && (
          <p role="alert" className="text-sm text-destructive">
            {refusal(remove.error)}
          </p>
        )}
        {remove.error instanceof StudioError && remove.error.status === 409 ? (
          <div className="flex flex-wrap items-center gap-3">
            <ReloadLatest
              gameSystemKey={gameSystemKey}
              onLoaded={() => {
                setOpen(false)
              }}
            />
          </div>
        ) : (
          <Button
            variant="destructive"
            disabled={remove.isPending}
            onClick={() => {
              remove.mutate(
                { expectedVersion: version },
                {
                  onSuccess: () => {
                    setOpen(false)
                  },
                },
              )
            }}
          >
            Remove the layout
          </Button>
        )}
      </DialogContent>
    </Dialog>
  )
}

/** Failing keeps the dialog open with what was typed; it closes once the API has answered. */
function EditLayoutDialog({
  gameSystemKey,
  sheet,
  editable,
  onDraftChange,
}: {
  gameSystemKey: string
  sheet: GameSystemSheet
  editable: boolean
  onDraftChange: (draft: LayoutDraft | null) => void
}) {
  const [open, setOpen] = useState(false)
  const save = useSaveSheetLayout(gameSystemKey)

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        save.reset()
      }}
    >
      <DialogTrigger asChild>
        <Button variant="outline" size="sm" disabled={!editable}>
          Edit layout
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Edit layout</DialogTitle>
          <DialogDescription>
            The publisher checks the layout when you save it; nothing is saved if it refuses.
          </DialogDescription>
        </DialogHeader>
        <LayoutForm
          gameSystemKey={gameSystemKey}
          sheet={sheet}
          save={save}
          onDraftChange={onDraftChange}
          onSaved={() => {
            setOpen(false)
          }}
        />
      </DialogContent>
    </Dialog>
  )
}

function LayoutForm({
  gameSystemKey,
  sheet,
  save,
  onDraftChange,
  onSaved,
}: {
  gameSystemKey: string
  sheet: GameSystemSheet
  save: ReturnType<typeof useSaveSheetLayout>
  onDraftChange: (draft: LayoutDraft | null) => void
  onSaved: () => void
}) {
  // Filled once, when the dialog opens, from the sheet it was read at: a refetch while the dialog
  // is open must not make a stale draft look current.
  const [base, setBase] = useState(sheet)
  const [draft, setDraft] = useState<LayoutDraft>(
    () => readLayout(base) ?? { columns: 1, groups: [] },
  )
  const [errors, setErrors] = useState<LayoutErrors | null>(null)
  // The draft the last save sent: the API names groups by their position in that request, so its
  // 422 answer is read against the draft only while the draft is still that one.
  const [sent, setSent] = useState<LayoutDraft | null>(null)
  const [reloadNote, setReloadNote] = useState<string | null>(null)
  const latest = useGameSystemSheet(gameSystemKey)
  const { error } = save

  useEffect(() => {
    onDraftChange(draft)
  }, [draft, onDraftChange])
  // The form is gone with its dialog: the preview goes back to the saved layout.
  useEffect(
    () => () => {
      onDraftChange(null)
    },
    [onDraftChange],
  )

  const refused = error instanceof SheetError && error.status === 422
  const answer = refused && sent === draft ? sheetLayoutAnswer(error.message) : null
  // A 422 about a draft that has changed since says nothing about this one.
  const message =
    errors?.form ??
    (error === null || (refused && answer === null)
      ? null
      : answer
        ? answer.form
        : refusal(error)) ??
    ''
  const columnsError = errors?.columns ?? answer?.columns ?? undefined

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const result = writeLayout(draft)
    if ('errors' in result) {
      setErrors(result.errors)
      return
    }
    setErrors(null)
    setSent(draft)
    save.mutate({ expectedVersion: base.version, layout: result.request }, { onSuccess: onSaved })
  }

  function reload() {
    void latest.refetch().then((result) => {
      const data = result.data
      if (result.isError || data === undefined) {
        setReloadNote(reloadFailed)
        return
      }
      // The layout was removed meanwhile: the panel drops this dialog with it.
      if (data.groups === null) {
        return
      }
      const reloaded = readLayout(data)
      if (reloaded === null) {
        setReloadNote(
          'The layout changed to a value this editor cannot show. Your changes are kept; the next save still conflicts.',
        )
        return
      }
      setDraft(reloaded)
      setBase(data)
      setErrors(null)
      setReloadNote(null)
      save.reset()
    })
  }

  // The group cards are memoised: these are stable, and the messages keep their reference while
  // their content is the same.
  const changeGroup = useCallback((index: number, changes: Partial<GroupDraft>) => {
    setDraft((current) => ({
      ...current,
      groups: current.groups.map((group, at) => (at === index ? { ...group, ...changes } : group)),
    }))
  }, [])
  const moveGroupAt = useCallback((index: number, direction: 'up' | 'down') => {
    setDraft((current) => moveGroup(current, index, direction))
  }, [])
  const removeGroupAt = useCallback((index: number) => {
    setDraft((current) => removeGroup(current, index))
  }, [])
  const groupErrors = useStableItems(
    draft.groups.map((_, index) => ({ ...errors?.groups[index], ...answer?.groups[index] })),
  )

  return (
    <form aria-label="Edit layout" className="space-y-4" onSubmit={handleSubmit}>
      <SelectField
        label="Sheet columns"
        value={String(draft.columns)}
        onChange={(value) => {
          const columns = Number(value)
          // A group cannot span more columns than the sheet has.
          setDraft((current) => ({
            columns,
            groups: current.groups.map((group) => ({
              ...group,
              span: Math.min(group.span, columns),
            })),
          }))
        }}
        options={numbered(maxSheetColumns)}
        error={columnsError}
      />
      {draft.groups.map((group, index) => (
        <GroupFields
          key={group.id}
          group={group}
          index={index}
          last={index === draft.groups.length - 1}
          sheetColumns={draft.columns}
          held={fieldsHeldBy(group, base.fields)}
          errors={groupErrors[index] ?? noGroupErrors}
          onChange={changeGroup}
          onMove={moveGroupAt}
          onRemove={removeGroupAt}
        />
      ))}
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={() => {
          setDraft(addGroup(draft))
        }}
      >
        Add group
      </Button>
      {message !== '' && (
        <p role="alert" className="text-sm text-destructive">
          {message}
        </p>
      )}
      {reloadNote && (
        <p role="alert" className="text-sm text-destructive">
          {reloadNote}
        </p>
      )}
      <div className="flex flex-wrap gap-2">
        <Button type="submit" disabled={save.isPending}>
          Save layout
        </Button>
        {error instanceof StudioError && error.status === 409 && (
          <Button type="button" variant="outline" onClick={reload}>
            Reload the latest version
          </Button>
        )}
      </div>
    </form>
  )
}

type GroupErrors = Partial<Record<'name' | 'columns' | 'span', string>>

const noGroupErrors: GroupErrors = {}

const GroupFields = memo(function GroupFields({
  group,
  index,
  last,
  sheetColumns,
  held,
  errors,
  onChange,
  onMove,
  onRemove,
}: {
  group: GroupDraft
  index: number
  last: boolean
  sheetColumns: number
  held: number
  errors: GroupErrors
  onChange: (index: number, changes: Partial<GroupDraft>) => void
  onMove: (index: number, direction: 'up' | 'down') => void
  onRemove: (index: number) => void
}) {
  const holdId = useId()

  return (
    <fieldset className="space-y-3 rounded-xl p-3 ring-1 ring-foreground/10">
      <legend className="px-1 font-heading font-semibold">Group {index + 1}</legend>
      <TextField
        label="Name"
        value={group.name}
        onChange={(name) => {
          onChange(index, { name })
        }}
        error={errors.name}
      />
      <div className="grid grid-cols-2 gap-3">
        <SelectField
          label="Columns"
          value={String(group.columns)}
          onChange={(value) => {
            onChange(index, { columns: Number(value) })
          }}
          options={numbered(maxGroupColumns)}
          error={errors.columns}
        />
        <SelectField
          label="Span"
          value={String(group.span)}
          onChange={(value) => {
            onChange(index, { span: Number(value) })
          }}
          options={numbered(sheetColumns)}
          error={errors.span}
        />
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={index === 0}
          onClick={() => {
            onMove(index, 'up')
          }}
        >
          Move up
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={last}
          onClick={() => {
            onMove(index, 'down')
          }}
        >
          Move down
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={held > 0}
          aria-describedby={held > 0 ? holdId : undefined}
          onClick={() => {
            onRemove(index)
          }}
        >
          Delete group
        </Button>
      </div>
      {held > 0 && (
        <p id={holdId} className="text-sm text-muted-foreground">
          Holds {held} {held === 1 ? 'field' : 'fields'}: move or delete{' '}
          {held === 1 ? 'it' : 'them'} first.
        </p>
      )}
    </fieldset>
  )
})
