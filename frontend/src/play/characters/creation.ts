import { parseFormula } from '@/shared/lib/formula'
import { computeDerived, evaluateOnForm, type DerivedResult } from '@/shared/sheet/derivedValues'
import type { Draft } from '@/shared/sheet/sheetValues'
import type { SheetFieldView, TrackerReading } from '@/shared/sheet/types'

import type { Campaign } from '../campaigns/useCampaigns'

/** How a Character is created when the sheet offers a choice (ADR 0040, 0043). */
export type CreationMethod = 'roll' | 'points'
export type PointBudget = Campaign['pinnedRelease']['pointBudgets'][number]

/** What the creation form asks of the player and leaves to the server, for the chosen method. */
export interface CreationPlan {
  /** The player picks the method: some budget covers a field with a dice default. */
  choice: boolean
  /** Whether the form may be submitted as far as the method goes. */
  ready: boolean
  /** The method to send; none when the sheet has no budget or the player has not chosen. */
  method: CreationMethod | null
  /** The fields the server sets (rolled numbers, start max tracks) and what the form says about each. */
  setAtCreation: Map<string, string>
  /** The budget fields the player gives a value, each with the budget that bounds it. */
  bounded: Map<string, PointBudget>
  /** The budgets the player spends, in list order: each has a points counter. */
  spent: PointBudget[]
}

const rolls = (field: SheetFieldView | undefined) => (field?.roll ?? null) !== null

/**
 * Which fields of a new Character the player sets and which the server does. A budget is
 * *rollable* when it covers a dice field. The player picks a method only when some budget is
 * rollable. By roll the server rolls every dice default and the player spends only the budgets
 * that are not rollable (a rollable budget's other fields are set inside its bounds, with no
 * total); by points the player spends every budget and the server rolls the dice fields outside
 * them. With nothing to roll the method is points without a choice. Until the player chooses,
 * the form reads as by roll.
 */
export function creationPlan(
  fields: SheetFieldView[],
  budgets: PointBudget[],
  chosen: CreationMethod | null,
): CreationPlan {
  const byKey = new Map(fields.map((field) => [field.key, field]))
  const rollable = (budget: PointBudget) => budget.fields.some((key) => rolls(byKey.get(key)))
  const choice = budgets.some(rollable)
  const method: CreationMethod | null = budgets.length === 0 ? null : choice ? chosen : 'points'
  const spent = budgets.filter((budget) => method === 'points' || !rollable(budget))
  const spentKeys = new Set(spent.flatMap((budget) => budget.fields))

  const setAtCreation = new Map<string, string>()
  const bounded = new Map<string, PointBudget>()
  for (const field of fields) {
    const budget = budgets.find(({ fields: covered }) => covered.includes(field.key))
    if (field.type === 'number' && rolls(field) && !spentKeys.has(field.key)) {
      setAtCreation.set(field.key, `Rolled at creation: ${field.roll ?? ''}`)
    } else if (field.type === 'track' && (field.startMax ?? null) !== null) {
      // The form completes the note once it holds the drafts (`creationNotes`).
      setAtCreation.set(field.key, 'Set at creation')
    } else if (budget !== undefined) {
      bounded.set(field.key, budget)
    }
  }

  return { choice, ready: !choice || chosen !== null, method, setAtCreation, bounded, spent }
}

/** "A", "A and B" or "A, B and C". */
function plainList(labels: string[]) {
  return labels.length < 2
    ? (labels[0] ?? '')
    : `${labels.slice(0, -1).join(', ')} and ${labels.at(-1) ?? ''}`
}

/** The server's bounds for a start max (`CharacterValues::MAX_TRACK`). */
const MAX_TRACK = 999

/**
 * What the form says about each field the server sets at creation. A track's start max is its value
 * when the form can compute it, clamped as the server does; otherwise the fields its formula reads,
 * in plain words, never the formula. The values the server rolls are not in the drafts' hands, so they
 * are dropped before evaluating: the drafts hold their default or budget-bound start, and a formula
 * over them would show a plausible but wrong value.
 */
export function creationNotes(
  plan: CreationPlan,
  fields: SheetFieldView[],
  drafts: Record<string, Draft>,
  trackers: TrackerReading[],
): Record<string, string> {
  const known = withoutSetAtCreation(plan, drafts)
  return Object.fromEntries(
    [...plan.setAtCreation].map(([key, note]) => {
      const field = fields.find((candidate) => candidate.key === key)
      if (field?.type !== 'track' || (field.startMax ?? null) === null) {
        return [key, note]
      }
      const formula = field.startMax ?? ''
      const value = evaluateOnForm(fields, formula, known, trackers)
      if (value !== undefined) {
        return [key, `Set at creation: ${String(Math.max(0, Math.min(MAX_TRACK, value)))}`]
      }
      return [key, `Set at creation${readsText(formula, fields)}`]
    }),
  )
}

/** The drafts the player holds, less the fields the server sets: theirs are only a default or a start. */
function withoutSetAtCreation(plan: CreationPlan, drafts: Record<string, Draft>) {
  return Object.fromEntries(Object.entries(drafts).filter(([key]) => !plan.setAtCreation.has(key)))
}

/**
 * The derived values of a new Character's form. A formula that reads a field the server sets has no
 * value yet, so it says `set at creation` rather than a number made of the field's default. Derived
 * values that read such a derived value keep the `depends on` reason; the others are live as typed.
 */
export function derivedOnForm(
  plan: CreationPlan,
  fields: SheetFieldView[],
  drafts: Record<string, Draft>,
  trackers: TrackerReading[],
): Record<string, DerivedResult> {
  const results = computeDerived(fields, withoutSetAtCreation(plan, drafts), trackers)
  return Object.fromEntries(
    Object.entries(results).map(([key, result]) => {
      const formula = fields.find((field) => field.key === key)?.formula ?? ''
      const unset =
        'reason' in result &&
        result.reason === 'incomplete value' &&
        readKeys(formula).some((read) => plan.setAtCreation.has(read))
      return [key, unset ? { reason: 'set at creation' } : result]
    }),
  )
}

/** The keys of the fields a formula reads, tracks' maximums included; none when it cannot be parsed. */
function readKeys(formula: string): string[] {
  try {
    return parseFormula(formula)
      .references.filter(({ kind }) => kind === 'field' || kind === 'trackMax')
      .map(({ key }) => key)
  } catch {
    return []
  }
}

/** " from A and B" for the fields a formula reads; nothing when it reads none or cannot be parsed. */
function readsText(formula: string, fields: SheetFieldView[]) {
  const keys = [...new Set(readKeys(formula))]
  const labels = keys.map((key) => fields.find((field) => field.key === key)?.label)
  // A field that cannot be named leaves the plain note rather than a half-named one.
  return keys.length === 0 || labels.some((label) => label === undefined)
    ? ''
    : ` from ${plainList(labels.filter((label) => label !== undefined))}`
}

/** `field` with its bounds narrowed by the budget's: what the player may give it at creation. */
export function withBudgetBounds(field: SheetFieldView, budget: PointBudget): SheetFieldView {
  return {
    ...field,
    min: budget.min === null ? field.min : Math.max(field.min ?? budget.min, budget.min),
    max: budget.max === null ? field.max : Math.min(field.max ?? budget.max, budget.max),
  }
}

/** The points the drafts of the budget fields add up to; a blank or non-numeric draft counts for 0. */
export function pointsSpent(budget: PointBudget, drafts: Record<string, Draft>): number {
  return budget.fields.reduce((total, key) => {
    const draft = drafts[key]
    return total + (typeof draft === 'string' ? Number(draft) || 0 : 0)
  }, 0)
}

/** What a bounded field's range reads as: "between 0 and 3", "at least 1" or "at most 3"; null without bounds. */
export function rangeText(field: SheetFieldView): string | null {
  const { min, max } = field
  if (min != null && max != null) return `between ${String(min)} and ${String(max)}`
  if (min != null) return `at least ${String(min)}`
  if (max != null) return `at most ${String(max)}`
  return null
}

/**
 * The budget fields whose typed draft lies outside the range their budget narrows them to, with
 * that range as text. A blank draft is not out of range (it counts as 0 points); a non-numeric one
 * is, so it cannot be saved, although the counter also counts it as 0.
 */
export function outOfRange(
  fields: SheetFieldView[],
  bounded: Map<string, PointBudget>,
  drafts: Record<string, Draft>,
): { field: SheetFieldView; range: string }[] {
  return fields.flatMap((field) => {
    const budget = bounded.get(field.key)
    const draft = drafts[field.key]
    if (budget === undefined || typeof draft !== 'string' || draft.trim() === '') return []
    const { min, max } = withBudgetBounds(field, budget)
    const value = Number(draft)
    const range = rangeText({ ...field, min, max })
    const outside =
      Number.isNaN(value) || (min != null && value < min) || (max != null && value > max)
    return outside && range !== null ? [{ field, range }] : []
  })
}
