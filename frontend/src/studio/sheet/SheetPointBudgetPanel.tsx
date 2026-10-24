import { Coins } from 'lucide-react'
import { useId, useState, type SubmitEvent } from 'react'

import { FieldError } from '@/shared/ui/alert'
import { Button } from '@/shared/ui/button'
import { Checkbox } from '@/shared/ui/checkbox'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/shared/ui/dialog'
import { IconTile } from '@/shared/ui/icon-tile'
import { Label } from '@/shared/ui/label'

import { TextField } from '../narrative/TextField'
import { StudioError } from '../useGameSystems'
import { ReloadLatest } from './SheetLayoutPanel'
import { sheetPointBudgetAnswer } from './sheetError'
import {
  SheetError,
  useRemoveSheetPointBudget,
  useSaveSheetPointBudget,
  type GameSystemSheet,
  type PointBudgetRequest,
} from './useGameSystemSheet'

type PointBudget = NonNullable<GameSystemSheet['pointBudget']>

const unnamed = 'Points'

/** What the user typed, as the form holds it: numbers stay text until they are saved. */
interface BudgetDraft {
  label: string
  total: string
  fields: string[]
  min: string
  max: string
}

const emptyDraft: BudgetDraft = { label: '', total: '', fields: [], min: '', max: '' }

function draftOf(budget: PointBudget | null): BudgetDraft {
  return budget === null
    ? emptyDraft
    : {
        label: budget.label ?? '',
        total: String(budget.total),
        fields: budget.fields,
        min: budget.min === null ? '' : String(budget.min),
        max: budget.max === null ? '' : String(budget.max),
      }
}

const whole = /^-?\d+$/

/** A blank optional number is left out; text that is not a whole number is refused here. */
function readWhole(text: string): number | null | undefined {
  const trimmed = text.trim()
  if (trimmed === '') {
    return null
  }
  return whole.test(trimmed) ? Number(trimmed) : undefined
}

type BudgetInputs = Partial<Record<'label' | 'total' | 'min' | 'max', string>>

/** The request for a draft, or what is wrong with it: the server stays the authority on the rest. */
function writeBudget(
  draft: BudgetDraft,
  numberKeys: string[],
): { request: PointBudgetRequest } | { errors: BudgetInputs } {
  const errors: BudgetInputs = {}
  const total = readWhole(draft.total)
  const min = readWhole(draft.min)
  const max = readWhole(draft.max)
  if (total === null) {
    errors.total = 'Enter the total.'
  } else if (total === undefined) {
    errors.total = 'Enter a whole number.'
  }
  if (min === undefined) {
    errors.min = 'Enter a whole number.'
  }
  if (max === undefined) {
    errors.max = 'Enter a whole number.'
  }
  if (Object.keys(errors).length > 0 || total === null || total === undefined) {
    return { errors }
  }

  return {
    request: {
      ...(draft.label.trim() === '' ? {} : { label: draft.label.trim() }),
      total,
      fields: numberKeys.filter((key) => draft.fields.includes(key)),
      ...(min === null || min === undefined ? {} : { min }),
      ...(max === null || max === undefined ? {} : { max }),
    },
  }
}

function creationRange(budget: PointBudget) {
  if (budget.min !== null && budget.max !== null) {
    return `${String(budget.min)} to ${String(budget.max)}`
  }
  if (budget.min !== null) {
    return `at least ${String(budget.min)}`
  }
  return budget.max === null ? null : `at most ${String(budget.max)}`
}

/**
 * The sheet's point budget: what a Character spends over number fields when it is created, with
 * the creation-only bounds for them. Add or edit it in a dialog, or remove it.
 */
export function SheetPointBudgetPanel({
  gameSystemKey,
  sheet,
}: {
  gameSystemKey: string
  sheet: GameSystemSheet
}) {
  const remove = useRemoveSheetPointBudget(gameSystemKey)
  const budget = sheet.pointBudget
  const range = budget === null ? null : creationRange(budget)
  const labels = new Map(sheet.fields.map(({ key, label }) => [key, label]))

  return (
    <div className="panel">
      <div className="panel-head">
        <IconTile size="sm">
          <Coins />
        </IconTile>
        <h2 className="font-heading text-panel-title">Point budget</h2>
        <div className="ml-auto">
          {budget === null && (
            <BudgetDialog gameSystemKey={gameSystemKey} sheet={sheet} trigger="Add point budget" />
          )}
        </div>
      </div>
      <section aria-label="Point budget" className="space-y-2 panel-body text-sm">
        {budget === null ? (
          <p className="text-muted-foreground">Characters are created without a point budget.</p>
        ) : (
          <>
            <p className="font-medium">
              {budget.label ?? unnamed}: {budget.total}
            </p>
            <p>
              Spent over{' '}
              {budget.fields
                .map((key) => {
                  const label = labels.get(key)
                  return typeof label === 'string' ? label : key
                })
                .join(', ')}
            </p>
            {range !== null && <p className="hint">Creation range: {range}</p>}
            {remove.error && (
              <div
                role="alert"
                className="flex flex-wrap items-center gap-3 text-sm text-destructive"
              >
                <p>{remove.error.message}</p>
                {remove.error instanceof StudioError && remove.error.status === 409 && (
                  <ReloadLatest gameSystemKey={gameSystemKey} onLoaded={remove.reset} />
                )}
              </div>
            )}
            <div className="flex flex-wrap gap-2">
              <BudgetDialog gameSystemKey={gameSystemKey} sheet={sheet} trigger="Edit" />
              <Button
                variant="outline"
                size="sm"
                disabled={remove.isPending}
                onClick={() => {
                  remove.mutate({ expectedVersion: sheet.version })
                }}
              >
                Remove
              </Button>
            </div>
          </>
        )}
      </section>
    </div>
  )
}

/** Failing keeps the dialog open with what was typed; it closes once the API has answered. */
function BudgetDialog({
  gameSystemKey,
  sheet,
  trigger,
}: {
  gameSystemKey: string
  sheet: GameSystemSheet
  trigger: string
}) {
  const [open, setOpen] = useState(false)
  const save = useSaveSheetPointBudget(gameSystemKey)

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        save.reset()
      }}
    >
      <DialogTrigger asChild>
        <Button variant="outline" size="sm">
          {trigger}
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Point budget</DialogTitle>
          <DialogDescription>
            The publisher checks the budget when you save it; nothing is saved if it refuses.
          </DialogDescription>
        </DialogHeader>
        <BudgetForm
          gameSystemKey={gameSystemKey}
          sheet={sheet}
          save={save}
          onSaved={() => {
            setOpen(false)
          }}
        />
      </DialogContent>
    </Dialog>
  )
}

function BudgetForm({
  gameSystemKey,
  sheet,
  save,
  onSaved,
}: {
  gameSystemKey: string
  sheet: GameSystemSheet
  save: ReturnType<typeof useSaveSheetPointBudget>
  onSaved: () => void
}) {
  // Filled once, when the dialog opens: a refetch while it is open must not overwrite what was typed.
  const [draft, setDraft] = useState(() => draftOf(sheet.pointBudget))
  const [errors, setErrors] = useState<BudgetInputs>({})
  // The draft the last save sent: a 422 answer says nothing about a draft that has changed since.
  const [sent, setSent] = useState<BudgetDraft | null>(null)
  const { error } = save
  const numbers = sheet.fields.flatMap((field) =>
    field.type === 'number' && typeof field.key === 'string'
      ? [{ key: field.key, label: typeof field.label === 'string' ? field.label : field.key }]
      : [],
  )

  const refused = error instanceof SheetError && error.status === 422
  const answer = refused && sent === draft ? sheetPointBudgetAnswer(error.message) : null
  const inputs = { ...answer?.inputs, ...errors }
  const message = answer ? answer.form : refused ? null : (error?.message ?? null)

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const result = writeBudget(
      draft,
      numbers.map(({ key }) => key),
    )
    if ('errors' in result) {
      setErrors(result.errors)
      return
    }
    setErrors({})
    setSent(draft)
    save.mutate({ expectedVersion: sheet.version, budget: result.request }, { onSuccess: onSaved })
  }

  return (
    <form aria-label="Point budget" className="space-y-4" onSubmit={handleSubmit}>
      <TextField
        label="Label"
        value={draft.label}
        help={`Left empty, the points are called "${unnamed}".`}
        onChange={(label) => {
          setDraft({ ...draft, label })
        }}
        error={inputs.label}
      />
      <TextField
        label="Total"
        value={draft.total}
        onChange={(total) => {
          setDraft({ ...draft, total })
        }}
        error={inputs.total}
      />
      <BudgetFields
        numbers={numbers}
        selected={draft.fields}
        onChange={(fields) => {
          setDraft({ ...draft, fields })
        }}
        error={answer?.fields ?? undefined}
      />
      <div className="grid grid-cols-2 gap-3">
        <TextField
          label="Creation min"
          value={draft.min}
          onChange={(min) => {
            setDraft({ ...draft, min })
          }}
          error={inputs.min}
        />
        <TextField
          label="Creation max"
          value={draft.max}
          onChange={(max) => {
            setDraft({ ...draft, max })
          }}
          error={inputs.max}
        />
      </div>
      <p className="hint">
        Limits each field while creating a Character; the field keeps its own range afterwards.
      </p>
      {message !== null && message !== '' && (
        <p role="alert" className="text-sm text-destructive">
          {message}
        </p>
      )}
      <div className="flex flex-wrap items-center gap-2">
        <Button type="submit" disabled={save.isPending || numbers.length === 0}>
          Save point budget
        </Button>
        {error instanceof StudioError && error.status === 409 && (
          <ReloadLatest gameSystemKey={gameSystemKey} onLoaded={save.reset} />
        )}
      </div>
    </form>
  )
}

/** The number fields of the sheet, in sheet order: only those can be spent on. */
function BudgetFields({
  numbers,
  selected,
  onChange,
  error,
}: {
  numbers: { key: string; label: string }[]
  selected: string[]
  onChange: (fields: string[]) => void
  error?: string
}) {
  const prefix = useId()

  return (
    <fieldset className="space-y-2">
      <legend className="text-sm font-medium">Fields</legend>
      {numbers.length === 0 && (
        <p className="text-sm text-muted-foreground">
          This sheet has no number field to spend points on. Add one first.
        </p>
      )}
      {numbers.map(({ key, label }) => (
        <div key={key} className="flex items-center gap-2">
          <Checkbox
            id={`${prefix}-${key}`}
            checked={selected.includes(key)}
            onCheckedChange={(checked) => {
              onChange(
                checked === true ? [...selected, key] : selected.filter((other) => other !== key),
              )
            }}
          />
          <Label htmlFor={`${prefix}-${key}`}>
            {label} <span className="list-row-key">{key}</span>
          </Label>
        </div>
      ))}
      {error && <FieldError>{error}</FieldError>}
    </fieldset>
  )
}
