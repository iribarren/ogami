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

import { slugOf } from '../checks/checkDraft'
import { TextField } from '../narrative/TextField'
import { StudioError } from '../useGameSystems'
import { ReloadLatest } from './SheetLayoutPanel'
import { sheetPointBudgetAnswer } from './sheetError'
import {
  SheetError,
  useRemoveSheetPointBudgetEntry,
  useSaveSheetPointBudgetEntry,
  type GameSystemSheet,
  type PointBudgetRequest,
} from './useGameSystemSheet'

type PointBudget = GameSystemSheet['pointBudgets'][number]

const unnamed = 'Points'

/**
 * What the user typed, as the form holds it: numbers stay text until they are saved. While
 * `follow` is set a new budget's key follows its label, until the key is typed by hand.
 */
interface BudgetDraft {
  key: string
  follow: boolean
  label: string
  total: string
  fields: string[]
  min: string
  max: string
}

const emptyDraft: BudgetDraft = {
  key: '',
  follow: true,
  label: '',
  total: '',
  fields: [],
  min: '',
  max: '',
}

function draftOf(budget: PointBudget | null): BudgetDraft {
  return budget === null
    ? emptyDraft
    : {
        key: budget.key,
        follow: false,
        label: budget.label ?? '',
        total: String(budget.total),
        fields: budget.fields,
        min: budget.min === null ? '' : String(budget.min),
        max: budget.max === null ? '' : String(budget.max),
      }
}

/** The key rule of every Studio key; the server stays the authority. */
const keyRule = /^[a-z0-9-]{1,64}$/

const whole = /^-?\d+$/

/** A blank optional number is left out; text that is not a whole number is refused here. */
function readWhole(text: string): number | null | undefined {
  const trimmed = text.trim()
  if (trimmed === '') {
    return null
  }
  return whole.test(trimmed) ? Number(trimmed) : undefined
}

type BudgetInputs = Partial<Record<'key' | 'label' | 'total' | 'min' | 'max', string>>

/** The request for a draft, or what is wrong with it: the server stays the authority on the rest. */
function writeBudget(
  draft: BudgetDraft,
  numberKeys: string[],
  takenKeys: string[],
): { request: PointBudgetRequest } | { errors: BudgetInputs } {
  const errors: BudgetInputs = {}
  if (!keyRule.test(draft.key)) {
    errors.key = 'Use 1 to 64 lower case letters, digits and hyphens.'
  } else if (takenKeys.includes(draft.key)) {
    errors.key = 'Another budget uses this key.'
  }
  if (draft.label.trim() === '') {
    errors.label = 'Enter the label.'
  }
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
      label: draft.label.trim(),
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
 * The sheet's point budgets: what a Character spends over number fields when it is created, each
 * with the creation-only bounds for its fields. Add or edit one in a dialog, or remove it.
 */
export function SheetPointBudgetPanel({
  gameSystemKey,
  sheet,
}: {
  gameSystemKey: string
  sheet: GameSystemSheet
}) {
  const remove = useRemoveSheetPointBudgetEntry(gameSystemKey)
  const budgets = sheet.pointBudgets
  const labels = new Map(sheet.fields.map(({ key, label }) => [key, label]))

  return (
    <div className="panel">
      <div className="panel-head">
        <IconTile size="sm">
          <Coins />
        </IconTile>
        <h2 className="font-heading text-panel-title">Point budgets</h2>
        <div className="ml-auto">
          <BudgetDialog
            gameSystemKey={gameSystemKey}
            sheet={sheet}
            budget={null}
            trigger="Add point budget"
          />
        </div>
      </div>
      <section aria-label="Point budgets" className="space-y-3 panel-body text-sm">
        {budgets.length === 0 && (
          <p className="text-muted-foreground">Characters are created without a point budget.</p>
        )}
        {remove.error && (
          <div role="alert" className="flex flex-wrap items-center gap-3 text-sm text-destructive">
            <p>{remove.error.message}</p>
            {remove.error instanceof StudioError && remove.error.status === 409 && (
              <ReloadLatest gameSystemKey={gameSystemKey} onLoaded={remove.reset} />
            )}
          </div>
        )}
        <ul className="space-y-3">
          {budgets.map((budget) => {
            const range = creationRange(budget)
            return (
              <li key={budget.key} aria-label={budget.label ?? unnamed} className="space-y-2">
                <p className="font-medium">
                  {budget.label ?? unnamed}: {budget.total}{' '}
                  <span className="list-row-key">{budget.key}</span>
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
                <div className="flex flex-wrap gap-2">
                  <BudgetDialog
                    gameSystemKey={gameSystemKey}
                    sheet={sheet}
                    budget={budget}
                    trigger="Edit"
                  />
                  <Button
                    variant="outline"
                    size="sm"
                    aria-label={`Remove ${budget.label ?? unnamed}`}
                    disabled={remove.isPending}
                    onClick={() => {
                      remove.mutate({ expectedVersion: sheet.version, budget: budget.key })
                    }}
                  >
                    Remove
                  </Button>
                </div>
              </li>
            )
          })}
        </ul>
      </section>
    </div>
  )
}

/**
 * Adds a budget, or edits `budget`. Failing keeps the dialog open with what was typed; it closes
 * once the API has answered.
 */
function BudgetDialog({
  gameSystemKey,
  sheet,
  budget,
  trigger,
}: {
  gameSystemKey: string
  sheet: GameSystemSheet
  budget: PointBudget | null
  trigger: string
}) {
  const [open, setOpen] = useState(false)
  const save = useSaveSheetPointBudgetEntry(gameSystemKey)

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        save.reset()
      }}
    >
      <DialogTrigger asChild>
        <Button
          variant="outline"
          size="sm"
          aria-label={budget === null ? undefined : `${trigger} ${budget.label ?? unnamed}`}
        >
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
          budget={budget}
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
  budget,
  save,
  onSaved,
}: {
  gameSystemKey: string
  sheet: GameSystemSheet
  budget: PointBudget | null
  save: ReturnType<typeof useSaveSheetPointBudgetEntry>
  onSaved: () => void
}) {
  // Filled once, when the dialog opens: a refetch while it is open must not overwrite what was typed.
  const [draft, setDraft] = useState(() => draftOf(budget))
  const [errors, setErrors] = useState<BudgetInputs>({})
  // The draft the last save sent: a 422 answer says nothing about a draft that has changed since.
  const [sent, setSent] = useState<BudgetDraft | null>(null)
  const { error } = save
  const numbers = sheet.fields.flatMap((field) =>
    field.type === 'number' && typeof field.key === 'string'
      ? [{ key: field.key, label: typeof field.label === 'string' ? field.label : field.key }]
      : [],
  )

  // The path indexes the list after the write: an edit keeps its position, an add is the last.
  const position =
    budget === null
      ? sheet.pointBudgets.length
      : sheet.pointBudgets.findIndex(({ key }) => key === budget.key)
  const refused = error instanceof SheetError && error.status === 422
  const answer = refused && sent === draft ? sheetPointBudgetAnswer(error.message, position) : null
  const inputs = { ...answer?.inputs, ...errors }
  const message = answer ? answer.form : refused ? null : (error?.message ?? null)

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const result = writeBudget(
      draft,
      numbers.map(({ key }) => key),
      // An edit keeps its own key; an add must not replace a budget the keyed PUT would overwrite.
      budget === null ? sheet.pointBudgets.map(({ key }) => key) : [],
    )
    if ('errors' in result) {
      setErrors(result.errors)
      return
    }
    setErrors({})
    setSent(draft)
    save.mutate(
      { expectedVersion: sheet.version, budget: draft.key, request: result.request },
      { onSuccess: onSaved },
    )
  }

  return (
    <form aria-label="Point budget" className="space-y-4" onSubmit={handleSubmit}>
      <TextField
        label="Label"
        value={draft.label}
        onChange={(label) => {
          setDraft({ ...draft, label, ...(draft.follow ? { key: slugOf(label) } : {}) })
        }}
        error={inputs.label}
      />
      {budget === null ? (
        <TextField
          label="Key"
          keyField
          value={draft.key}
          onChange={(key) => {
            setDraft({ ...draft, key, follow: false })
          }}
          error={inputs.key}
        />
      ) : (
        <div className="space-y-2">
          <p className="text-sm font-medium">Key</p>
          <p className="list-row-key">{draft.key}</p>
          <p className="hint">The key never changes once the budget is saved.</p>
        </div>
      )}
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
        Limits each field while creating a Character; the field keeps its own range afterwards. A
        field sits in one budget only.
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
