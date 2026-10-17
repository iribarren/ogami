import { useId, useState, type ReactNode, type SubmitEvent } from 'react'

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
import { NativeSelect } from '@/shared/ui/native-select'

import { TextField } from '../narrative/TextField'
import { StudioError } from '../useGameSystems'
import {
  blankField,
  fieldTypes,
  readField,
  typeProperties,
  writeField,
  type FieldDraft,
  type FieldErrors,
  type FieldType,
} from './sheetDraft'
import { sheetFieldAnswer } from './sheetError'
import {
  SheetError,
  useGameSystemSheet,
  useSaveSheetField,
  type GameSystemSheet,
} from './useGameSystemSheet'

/**
 * The create button or an Edit button (`original` is the key of the field being replaced) and its
 * dialog. Failing keeps the dialog open with what was typed; it only closes once the API has
 * answered with the saved sheet.
 */
export function SheetFieldDialog({
  gameSystemKey,
  sheet,
  original,
  trigger,
}: {
  gameSystemKey: string
  sheet: GameSystemSheet
  original: string | null
  trigger: ReactNode
}) {
  const [open, setOpen] = useState(false)
  const save = useSaveSheetField(gameSystemKey)

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        save.reset()
      }}
    >
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{original === null ? 'New field' : `Edit ${original}`}</DialogTitle>
          <DialogDescription>
            The publisher checks the field when you save it; nothing is saved if it refuses.
          </DialogDescription>
        </DialogHeader>
        <FieldForm
          gameSystemKey={gameSystemKey}
          sheet={sheet}
          original={original}
          save={save}
          onSaved={() => {
            setOpen(false)
          }}
        />
      </DialogContent>
    </Dialog>
  )
}

const typeLabels: Record<FieldType, string> = {
  number: 'Number',
  text: 'Text',
  list: 'List',
  track: 'Track',
  derived: 'Derived',
}

function FieldForm({
  gameSystemKey,
  sheet,
  original,
  save,
  onSaved,
}: {
  gameSystemKey: string
  sheet: GameSystemSheet
  original: string | null
  save: ReturnType<typeof useSaveSheetField>
  onSaved: () => void
}) {
  // The form is filled once, when the dialog opens, from the sheet it was read at: a refetch while
  // the dialog is open must not make a stale draft look current.
  const [base, setBase] = useState(sheet)
  const [draft, setDraft] = useState<FieldDraft>(() => {
    const stored = base.fields.find((field) => field.key === original)
    return (stored && readField(stored)) ?? blankField(base.groups)
  })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [reloadNote, setReloadNote] = useState<string | null>(null)
  const latest = useGameSystemSheet(gameSystemKey)
  const { error } = save

  const position =
    original === null ? base.fields.length : base.fields.findIndex((f) => f.key === original)
  const answer =
    error instanceof SheetError && error.status === 422
      ? sheetFieldAnswer(error.message, position)
      : null
  const message =
    error === null
      ? ''
      : `${answer ? (answer.form ?? '') : error.message}${
          error instanceof SheetError && error.paths.length > 0
            ? ` Used by ${error.paths.join(', ')}.`
            : ''
        }`
  const shown: Record<string, string | undefined> = { ...errors, ...answer?.fields }
  const renamed = original !== null && draft.key.trim() !== original
  const publishedRename = renamed && base.publishedFieldKeys.includes(original)
  const columns = base.groups?.find((group) => group.name === draft.group)?.columns ?? 1

  const change = (changes: Partial<FieldDraft>) => {
    setDraft((current) => ({ ...current, ...changes }))
  }

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const result = writeField(draft, base.groups)
    if ('errors' in result) {
      setErrors(result.errors)
      return
    }
    setErrors({})
    save.mutate(
      { expectedVersion: base.version, original, field: result.field },
      { onSuccess: onSaved },
    )
  }

  function reload() {
    void latest.refetch().then((result) => {
      const data = result.data
      if (result.isError || data === undefined) {
        setReloadNote("Couldn't reload the latest version. Try again.")
        return
      }
      // A refused key (409 on another field) is not a stale version: the draft stays as typed.
      if (data.version !== base.version && original !== null) {
        const stored = data.fields.find((field) => field.key === original)
        if (stored === undefined) {
          // The field is gone: the list drops its row, and this dialog with it.
          return
        }
        const reloaded = readField(stored)
        if (reloaded === null) {
          setReloadNote(
            'The field changed to a shape this editor cannot read. Your changes are kept; the next save still conflicts.',
          )
          return
        }
        setDraft(reloaded)
      }
      setBase(data)
      setReloadNote(null)
      save.reset()
    })
  }

  return (
    <form
      aria-label={original === null ? 'New field' : `Edit ${original}`}
      className="space-y-4"
      onSubmit={handleSubmit}
    >
      <TextField
        label="Key"
        value={draft.key}
        onChange={(key) => {
          change({ key })
        }}
        error={shown.key}
        help={
          publishedRename
            ? 'Characters on campaigns that upgrade later lose that value.'
            : undefined
        }
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
      <SelectField
        label="Type"
        value={draft.type}
        onChange={(type) => {
          const blank = blankField(null)
          change({
            ...Object.fromEntries(typeProperties.map((name) => [name, blank[name]])),
            type: type as FieldType,
          })
        }}
        options={fieldTypes.map((type) => ({ value: type, label: typeLabels[type] }))}
      />
      {base.groups === null ? (
        <TextField
          label="Group"
          value={draft.group}
          onChange={(group) => {
            change({ group })
          }}
          error={shown.group}
        />
      ) : (
        <>
          <SelectField
            label="Group"
            value={draft.group}
            onChange={(group) => {
              const wide = base.groups?.find((declared) => declared.name === group)?.columns ?? 1
              change({ group, span: Number(draft.span) > wide ? '' : draft.span })
            }}
            options={base.groups.map((group) => ({ value: group.name, label: group.name }))}
            error={shown.group}
          />
          <SelectField
            label="Span"
            value={draft.span}
            onChange={(span) => {
              change({ span })
            }}
            options={[
              { value: '', label: 'Not set' },
              ...Array.from({ length: columns }, (_, at) => ({
                value: String(at + 1),
                label: String(at + 1),
              })),
            ]}
            error={shown.span}
          />
        </>
      )}
      <TypeFields draft={draft} change={change} errors={shown} />
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
          {original === null ? 'Create field' : 'Save field'}
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

/** The inputs of the properties the chosen type has. */
function TypeFields({
  draft,
  change,
  errors,
}: {
  draft: FieldDraft
  change: (changes: Partial<FieldDraft>) => void
  errors: Record<string, string | undefined>
}) {
  const multilineId = useId()
  const text = (label: string, name: keyof FieldDraft) => (
    <TextField
      label={label}
      value={String(draft[name])}
      onChange={(value) => {
        change({ [name]: value })
      }}
      error={errors[name]}
    />
  )

  switch (draft.type) {
    case 'number':
      return (
        <>
          {text('Min', 'min')}
          {text('Max', 'max')}
          {text('Default', 'default')}
        </>
      )
    case 'text':
      return (
        <>
          <div className="flex items-center gap-2">
            <input
              id={multilineId}
              type="checkbox"
              checked={draft.multiline}
              onChange={(event) => {
                change({ multiline: event.target.checked })
              }}
            />
            <Label htmlFor={multilineId}>Multiline</Label>
          </div>
          {text('Max length', 'maxLength')}
        </>
      )
    case 'list':
      return (
        <>
          {text('Item label', 'itemLabel')}
          {text('Max items', 'maxItems')}
        </>
      )
    case 'track':
      return text('Max', 'max')
    case 'derived':
      return text('Formula', 'formula')
  }
}

function SelectField({
  label,
  value,
  onChange,
  options,
  error,
}: {
  label: string
  value: string
  onChange: (value: string) => void
  options: { value: string; label: string }[]
  error?: string
}) {
  const id = useId()

  return (
    <div className="space-y-2">
      <Label htmlFor={id}>{label}</Label>
      <NativeSelect
        id={id}
        value={value}
        aria-invalid={error !== undefined}
        onChange={(event) => {
          onChange(event.target.value)
        }}
      >
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </NativeSelect>
      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  )
}
