import type { Draft } from '@/shared/sheet/sheetValues'
import type { SheetFieldView } from '@/shared/sheet/types'

import type { Campaign } from '../campaigns/useCampaigns'

/** How a Character is created when the sheet offers a choice (ADR 0040). */
export type CreationMethod = 'roll' | 'points'
export type PointBudget = NonNullable<Campaign['pinnedRelease']['pointBudget']>

/** What the creation form asks of the player and leaves to the server, for the chosen method. */
export interface CreationPlan {
  /** The player picks the method: the budget covers a field with a dice default. */
  choice: boolean
  /** Whether the form may be submitted as far as the method goes. */
  ready: boolean
  /** The method to send; none when the sheet has no budget or the player has not chosen. */
  method: CreationMethod | null
  /** The fields the server sets (rolled numbers, start max tracks) and what the form says about each. */
  setAtCreation: Map<string, string>
  /** The budget fields the player gives a value, bounded by the budget. */
  bounded: Set<string>
  /** Whether the player spends the budget: the points counter applies. */
  spent: boolean
}

const rolls = (field: SheetFieldView | undefined) => (field?.roll ?? null) !== null

/**
 * Which fields of a new Character the player sets and which the server does. By roll the server
 * rolls every dice default; the player only sets the budget fields without one. By points the
 * player spends the whole budget and the server rolls the dice fields outside it. A budget with
 * nothing to roll is spent without a choice. Until the player chooses, the form reads as by roll.
 */
export function creationPlan(
  fields: SheetFieldView[],
  budget: PointBudget | null,
  chosen: CreationMethod | null,
): CreationPlan {
  const byKey = new Map(fields.map((field) => [field.key, field]))
  const choice = budget?.fields.some((key) => rolls(byKey.get(key))) === true
  const method: CreationMethod | null = budget === null ? null : choice ? chosen : 'points'
  const spent = method === 'points'

  const setAtCreation = new Map<string, string>()
  const bounded = new Set<string>()
  for (const field of fields) {
    const inBudget = budget?.fields.includes(field.key) === true
    if (field.type === 'number' && rolls(field) && !(spent && inBudget)) {
      setAtCreation.set(field.key, `Rolled at creation: ${field.roll ?? ''}`)
    } else if (field.type === 'track' && (field.startMax ?? null) !== null) {
      setAtCreation.set(field.key, `Set at creation: ${field.startMax ?? ''}`)
    } else if (inBudget) {
      bounded.add(field.key)
    }
  }

  return { choice, ready: !choice || chosen !== null, method, setAtCreation, bounded, spent }
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
