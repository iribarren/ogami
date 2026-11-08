import { Wallet } from 'lucide-react'
import { memo, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { IconTile } from '@/shared/ui/icon-tile'

import { TextField } from '../narrative/TextField'
import { UnsavedChangesGuard } from '../UnsavedChangesGuard'
import { SelectField } from './SheetFieldDialog'
import { ReloadLatest } from './SheetLayoutPanel'
import { currencyAnswer } from './sheetError'
import {
  isVersionConflict,
  SheetError,
  useRemoveSheetCurrency,
  useSaveSheetCurrency,
  type GameSystemSheet,
} from './useGameSystemSheet'

/** The longest unit the publisher accepts. */
const unitLimit = 10

/**
 * The sheet's currency (ADR 0045): the number field Characters spend in shops, and the unit shown
 * after an amount. Saved on its own, like a point budget; the publisher checks it when you save.
 */
// Memoised: the Sheet tab re-renders on every keystroke of the layout draft, which says nothing here.
export const SheetCurrencyPanel = memo(function SheetCurrencyPanel({
  gameSystemKey,
  sheet,
}: {
  gameSystemKey: string
  sheet: GameSystemSheet
}) {
  const save = useSaveSheetCurrency(gameSystemKey)
  const remove = useRemoveSheetCurrency(gameSystemKey)
  const { currency } = sheet
  const [field, setField] = useState(currency?.field ?? '')
  const [unit, setUnit] = useState(currency?.unit ?? '')
  const [problem, setProblem] = useState<string | null>(null)
  // The stored currency the inputs started from, and the sheet version it was read at (what a save
  // or a removal sends). While the stored currency is still `base`, other sheet edits do not make
  // a currency save conflict, so `readAt` follows the live version. Once the stored currency moves
  // away from `base` (a refetch after another request), an unsaved edit is kept with the version
  // it was made at: a save then conflicts, and only a reload replaces the edit with the new
  // currency (the inputs, `base` and `readAt` change together or not at all).
  const [base, setBase] = useState({ field: currency?.field ?? '', unit: currency?.unit ?? '' })
  const [readAt, setReadAt] = useState(sheet.version)
  const storedChanged =
    (currency?.field ?? '') !== base.field || (currency?.unit ?? '') !== base.unit
  const dirty = field !== base.field || unit.trim() !== base.unit
  if (!storedChanged) {
    if (readAt !== sheet.version) {
      setReadAt(sheet.version)
    }
  } else if (!dirty) {
    // Nothing typed: the stored value is the newer truth.
    setBase({ field: currency?.field ?? '', unit: currency?.unit ?? '' })
    setField(currency?.field ?? '')
    setUnit(currency?.unit ?? '')
    setReadAt(sheet.version)
    setProblem(null)
  }
  // A save went through: the inputs stay as typed and now match what is stored.
  function saved(answer: GameSystemSheet) {
    setBase({ field: answer.currency?.field ?? '', unit: answer.currency?.unit ?? '' })
    setReadAt(answer.version)
  }
  // Replaces the inputs, `base` and `readAt` with a sheet just read, dropping any unsaved edit.
  function take(read: GameSystemSheet) {
    saved(read)
    setField(read.currency?.field ?? '')
    setUnit(read.currency?.unit ?? '')
    setProblem(null)
  }
  // The draft the last save sent: a 422 answer says nothing about a draft that has changed since.
  const [sent, setSent] = useState<string | null>(null)
  const numbers = sheet.fields.flatMap((stored) =>
    stored.type === 'number' && typeof stored.key === 'string'
      ? [{ key: stored.key, label: typeof stored.label === 'string' ? stored.label : stored.key }]
      : [],
  )
  const draft = `${field}\n${unit}`
  // A save trims the unit and the form keeps what was typed, so `dirty` compares the unit trimmed.
  const error = save.error ?? remove.error
  // Only a refused save is placed on the inputs, and only while the draft is the one it judged;
  // any other error (a refused removal, a 404, a library pack) shows its message as it is.
  const refusedSave =
    save.error instanceof SheetError && save.error.status === 422 ? save.error : null
  const answer = refusedSave !== null && sent === draft ? currencyAnswer(refusedSave.message) : null
  const message =
    problem ?? (answer ? answer.form : refusedSave !== null ? null : (error?.message ?? null))

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const trimmed = unit.trim()
    if (field === '') {
      setProblem('Pick the number field that holds the money.')
      return
    }
    if (trimmed.length > unitLimit) {
      setProblem(`The unit is at most ${String(unitLimit)} characters.`)
      return
    }
    setProblem(null)
    setSent(draft)
    remove.reset()
    save.mutate(
      { expectedVersion: readAt, field, unit: trimmed === '' ? null : trimmed },
      { onSuccess: saved },
    )
  }

  return (
    <div className="panel">
      <UnsavedChangesGuard dirty={dirty} blockTabChange />
      <div className="panel-head">
        <IconTile size="sm">
          <Wallet />
        </IconTile>
        <h2 className="font-heading text-panel-title">Currency</h2>
      </div>
      <section aria-label="Currency" className="space-y-3 panel-body text-sm">
        <p className="text-muted-foreground">
          {currency === null
            ? 'Characters have no money: shops cannot trade until a number field is the currency.'
            : `Characters spend ${currency.field}${currency.unit === null ? '' : ` in ${currency.unit}`}.`}
        </p>
        <form aria-label="Currency" className="space-y-3" onSubmit={handleSubmit}>
          <SelectField
            label="Currency field"
            value={field}
            onChange={setField}
            options={[
              { value: '', label: numbers.length === 0 ? 'No number field yet' : 'Not set' },
              ...numbers.map(({ key, label }) => ({ value: key, label: `${label} (${key})` })),
            ]}
            error={answer?.inputs.field}
          />
          <TextField
            label="Unit"
            value={unit}
            onChange={setUnit}
            error={answer?.inputs.unit}
            help="Shown after an amount, e.g. eb. Leave empty for none."
          />
          {storedChanged && dirty && (
            <p role="alert" className="text-sm text-destructive">
              The stored currency changed since you started editing. Reloading replaces your edit
              with it.
            </p>
          )}
          {message !== null && message !== '' && (
            <p role="alert" className="text-sm text-destructive">
              {message}
            </p>
          )}
          <div className="flex flex-wrap items-center gap-2">
            <Button type="submit" disabled={save.isPending || numbers.length === 0}>
              Save currency
            </Button>
            {currency !== null && (
              <Button
                type="button"
                variant="outline"
                disabled={remove.isPending}
                onClick={() => {
                  save.reset()
                  setProblem(null)
                  remove.mutate({ expectedVersion: readAt }, { onSuccess: take })
                }}
              >
                Remove currency
              </Button>
            )}
            {(isVersionConflict(error) || (storedChanged && dirty)) && (
              <ReloadLatest
                gameSystemKey={gameSystemKey}
                onLoaded={(read) => {
                  take(read)
                  save.reset()
                  remove.reset()
                }}
              />
            )}
          </div>
        </form>
      </section>
    </div>
  )
})
