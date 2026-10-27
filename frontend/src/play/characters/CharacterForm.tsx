import { useId, useState, type SubmitEvent } from 'react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'
import { computeDerived } from '@/shared/sheet/derivedValues'
import { SheetFields } from '@/shared/sheet/SheetFields'
import { fromDraft, storedFields, toDraft, type Draft } from '@/shared/sheet/sheetValues'
import type { SheetFieldView, SheetTemplateView } from '@/shared/sheet/types'

import {
  creationPlan,
  outOfRange,
  pointsSpent,
  withBudgetBounds,
  type CreationMethod,
  type PointBudget,
} from './creation'
import { TagFields, type ReleaseTag } from '../lists/TagFields'
import { useFreeTags } from '../lists/useFreeTags'
import type { Tracker } from '../trackers/useTrackers'
import type { Character, CharacterFields } from './useCharacters'

const maxName = 100

/**
 * A budget field of a new Character starts as its default inside the bounds the budget gives it,
 * so the browser does not refuse a default such as 0 before the player has typed anything.
 */
function startingDraft(field: SheetFieldView, budget: PointBudget): Draft {
  const { min, max } = withBudgetBounds(field, budget)
  const start = Number(toDraft(field, undefined))
  return String(Math.min(max ?? start, Math.max(min ?? start, start)))
}

/**
 * Adds or edits a Character: name, the pinned release's sheet fields, Tags and free tags. It
 * always submits the whole Character. The API validates; its refusal shows as it comes and the
 * form keeps what was typed. Derived fields are shown read-only, recomputed as the player types
 * from the drafts and the Campaign's `trackers`; they are never submitted.
 *
 * Adding follows the sheet's creation rules (ADR 0040 and 0043, see `creationPlan`): the player
 * picks Roll or Spend points when a point budget covers a dice field, spends the points of each
 * budget against its own counter, and never sends a value for what the server sets (rolled numbers, start max tracks). Editing
 * ignores all of it.
 */
export function CharacterForm({
  character,
  sheet,
  trackers,
  releaseTags,
  pointBudgets = [],
  pending,
  error,
  onSubmit,
  onCancel,
}: {
  character: Character | null
  /** Every field of the sheet, derived ones included, and how it is laid out. */
  sheet: SheetTemplateView
  trackers: Tracker[]
  releaseTags: ReleaseTag[]
  /** The sheet's point budgets; used only when adding a Character. */
  pointBudgets?: PointBudget[]
  pending: boolean
  error: string | undefined
  onSubmit: (fields: CharacterFields) => void
  /** Without it the form has no Cancel: the step that shows it has nothing to go back to. */
  onCancel?: (() => void) | undefined
}) {
  const id = useId()
  const free = useFreeTags(character?.freeTags ?? [])
  // A field the Character has no value for starts as its default.
  const stored = storedFields(sheet.fields)
  const [chosen, setChosen] = useState<CreationMethod | null>(null)
  const plan = character === null ? creationPlan(sheet.fields, pointBudgets, chosen) : null
  const [drafts, setDrafts] = useState<Record<string, Draft>>(() =>
    Object.fromEntries(
      stored.map((field) => {
        const budget = pointBudgets.find(({ fields }) => fields.includes(field.key))
        return [
          field.key,
          character === null && budget !== undefined
            ? startingDraft(field, budget)
            : toDraft(field, character?.values[field.key]),
        ]
      }),
    ),
  )
  const shown: SheetTemplateView =
    plan === null
      ? sheet
      : {
          ...sheet,
          fields: sheet.fields.map((field) => {
            const budget = plan.bounded.get(field.key)
            return budget === undefined ? field : withBudgetBounds(field, budget)
          }),
        }
  const counters = (plan?.spent ?? []).map((budget) => ({
    budget,
    spent: pointsSpent(budget, drafts),
  }))
  const overBudget = counters.some(({ budget, spent }) => spent > budget.total)
  // The input attributes bound the field, but a typed value outside them still reaches the draft.
  const outside = plan === null ? [] : outOfRange(sheet.fields, plan.bounded, drafts)

  function handleSubmit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const data = new FormData(event.currentTarget)
    const name = data.get('name')
    onSubmit({
      name: typeof name === 'string' ? name : '',
      tags: data.getAll('tags').filter((tag) => typeof tag === 'string'),
      freeTags: free.toSave(),
      // What the server sets when the Character is created is never sent: it would be refused.
      values: Object.fromEntries(
        stored
          .filter((field) => plan?.setAtCreation.has(field.key) !== true)
          .map((field) => [
            field.key,
            fromDraft(field, drafts[field.key] ?? toDraft(field, undefined)),
          ]),
      ),
      ...(plan?.method == null ? {} : { method: plan.method }),
    })
  }

  return (
    <form
      aria-label={character === null ? 'Add Character' : `Edit ${character.name}`}
      className="space-y-3 rounded-lg border border-border p-3"
      onSubmit={handleSubmit}
    >
      <fieldset disabled={pending} className="space-y-3">
        <div className="space-y-1">
          <Label htmlFor={`${id}-name`}>Name</Label>
          <Input
            id={`${id}-name`}
            name="name"
            required
            maxLength={maxName}
            defaultValue={character?.name ?? ''}
          />
        </div>
        {plan?.choice === true && (
          <div
            role="radiogroup"
            aria-label="How do you create this Character?"
            className="space-y-1"
          >
            <p className="text-sm font-medium">How do you create this Character?</p>
            {(
              [
                ['roll', 'Roll'],
                ['points', 'Spend points'],
              ] as const
            ).map(([method, label]) => (
              <label key={method} className="flex items-center gap-2">
                <input
                  type="radio"
                  name={`${id}-method`}
                  checked={chosen === method}
                  onChange={() => {
                    setChosen(method)
                  }}
                />
                {label}
              </label>
            ))}
          </div>
        )}
        {counters.map(({ budget, spent }) => (
          <p
            key={budget.key}
            aria-live="polite"
            className={spent > budget.total ? 'text-sm font-medium text-destructive' : 'text-sm'}
          >
            {budget.label ?? 'Points'}: {spent} / {budget.total}
            {spent > budget.total && ' (over budget)'}
          </p>
        ))}
        {outside.map(({ field, range }) => (
          <p key={field.key} role="alert" className="text-sm font-medium text-destructive">
            {field.label}: {range}.
          </p>
        ))}
        <SheetFields
          template={shown}
          drafts={drafts}
          derived={computeDerived(sheet.fields, drafts, trackers)}
          notes={Object.fromEntries(plan?.setAtCreation ?? [])}
          onChange={(key, draft) => {
            setDrafts({ ...drafts, [key]: draft })
          }}
        />
        <TagFields
          id={id}
          kind="character"
          releaseTags={releaseTags}
          held={character?.tags ?? []}
          free={free}
        />
        <div className="flex gap-2">
          <Button
            type="submit"
            size="sm"
            disabled={plan?.ready === false || overBudget || outside.length > 0}
          >
            Save
          </Button>
          {onCancel !== undefined && (
            <Button type="button" variant="ghost" size="sm" onClick={onCancel}>
              Cancel
            </Button>
          )}
        </div>
      </fieldset>
      {error !== undefined && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
    </form>
  )
}
