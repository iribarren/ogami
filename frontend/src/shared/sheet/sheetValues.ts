import type { SheetEconomy, SheetFieldView, SheetGear, SheetStack, SheetValue } from './types'

/**
 * What a field holds while it is edited: text as typed, so a number can be empty for a moment
 * (the browser then refuses the form), the rows of a list, the marked option keys of a conditions
 * field, an inventory's stacks, a track's current and max.
 */
export type Draft = string | string[] | SheetStack[] | { current: string; max: string }

/**
 * The fields a Character stores a value for: every field but the derived ones, which the API
 * computes and refuses a value for.
 */
export function storedFields(sheet: SheetFieldView[]): SheetFieldView[] {
  return sheet.filter((field) => field.type !== 'derived')
}

/** The value of `field`: the stored one, or its default when there is none or it has another shape. */
export function valueOrDefault(field: SheetFieldView, stored: SheetValue | undefined): SheetValue {
  switch (field.type) {
    case 'number':
      return typeof stored === 'number' ? stored : (field.default ?? 0)
    case 'text':
      return typeof stored === 'string' ? stored : ''
    case 'list':
    case 'conditions':
    case 'inventory':
      return Array.isArray(stored) ? stored : []
    case 'track':
      return typeof stored === 'object' && !Array.isArray(stored)
        ? stored
        : { current: field.max ?? 0, max: field.max ?? 0 }
    case 'derived':
      // Never stored: storedFields() leaves derived fields out of the form and the summary.
      return 0
  }
}

export function toDraft(field: SheetFieldView, stored: SheetValue | undefined): Draft {
  const value = valueOrDefault(field, stored)
  if (typeof value === 'number') {
    return String(value)
  }
  if (field.type === 'inventory') {
    return stacksOf(value)
  }
  if (typeof value === 'object' && !Array.isArray(value)) {
    return { current: String(value.current), max: String(value.max) }
  }
  return typeof value === 'string' ? value : value.filter(isText)
}

function isText(item: unknown): item is string {
  return typeof item === 'string'
}

function isStack(item: unknown): item is SheetStack {
  if (typeof item !== 'object' || item === null || !('quantity' in item)) {
    return false
  }
  return 'gear' in item || ('name' in item && 'slots' in item)
}

/** The well-formed stacks of a stored value or a draft; anything else (a malformed stored value) reads as none. */
export function stacksOf(value: unknown): SheetStack[] {
  return Array.isArray(value) ? value.filter(isStack) : []
}

/** The catalog entry a stack names; null for a free stack or gear the catalog no longer has. */
export function stackGear(stack: SheetStack, gear: SheetGear[]): SheetGear | null {
  return 'gear' in stack ? (gear.find(({ key }) => key === stack.gear) ?? null) : null
}

/** What a stack is called: a free stack's own name, else the catalog name (the key when the catalog lacks it). */
export function stackName(stack: SheetStack, gear: SheetGear[]): string {
  return 'name' in stack ? stack.name : (stackGear(stack, gear)?.name ?? stack.gear)
}

/** The slots all the units of a stack take; gear missing from the catalog counts for none. */
export function stackSlots(stack: SheetStack, gear: SheetGear[]): number {
  const perUnit = 'slots' in stack ? stack.slots : (stackGear(stack, gear)?.slots ?? 0)
  return perUnit * stack.quantity
}

export function slotsUsed(stacks: SheetStack[], gear: SheetGear[]): number {
  return stacks.reduce((total, stack) => total + stackSlots(stack, gear), 0)
}

/** "Name" for one unit, "Name ×n" for more. */
export function stackText(stack: SheetStack, gear: SheetGear[]): string {
  const name = stackName(stack, gear)
  return stack.quantity > 1 ? `${name} ×${String(stack.quantity)}` : name
}

/** "12 eb" with the currency's unit; the bare amount for any other field or a currency without one. */
export function withUnit(
  field: SheetFieldView,
  amount: string,
  economy: SheetEconomy | undefined,
): string {
  const unit = economy?.currency?.field === field.key ? economy.currency.unit : null
  return unit === null ? amount : `${amount} ${unit}`
}

/** The value to send for a draft; blank list rows are dropped. */
export function fromDraft(field: SheetFieldView, draft: Draft): SheetValue {
  if (field.type === 'inventory') {
    return stacksOf(draft)
  }
  if (typeof draft === 'string') {
    return field.type === 'number' ? Number(draft) : draft
  }
  if (Array.isArray(draft)) {
    return draft
      .filter(isText)
      .map((item) => item.trim())
      .filter((item) => item !== '')
  }
  return { current: Number(draft.current), max: Number(draft.max) }
}

const summaryLength = 40

function cut(text: string): string {
  return text.length > summaryLength ? `${text.slice(0, summaryLength)}…` : text
}

/**
 * The items of a list that fit the summary budget, joined; an item that alone exceeds it is cut,
 * and `+N` counts the items left out.
 */
function summarizeItems(items: string[]): string {
  const shown: string[] = []
  let length = 0
  for (const item of items) {
    length += item.length + (shown.length > 0 ? 2 : 0)
    if (length > summaryLength) {
      break
    }
    shown.push(item)
  }
  if (shown.length === 0) {
    shown.push(cut(items[0] ?? ''))
  }
  const rest = items.length - shown.length
  return rest > 0 ? `${shown.join(', ')} +${String(rest)}` : shown.join(', ')
}

/** The labels of the options a conditions field marks, in declared order; a key the field no longer declares is left out. */
export function markedLabels(field: SheetFieldView, marked: string[]): string[] {
  return (field.options ?? []).filter(({ key }) => marked.includes(key)).map(({ label }) => label)
}

/** One short phrase for a field's value, as the Characters list shows it. */
export function summarize(
  field: SheetFieldView,
  stored: SheetValue | undefined,
  derived: Record<string, number | null> = {},
  economy?: SheetEconomy,
): string | null {
  if (field.type === 'derived') {
    // Computed by the server, never here.
    return `${field.label}: ${String(derived[field.key] ?? '—')}`
  }
  const value = valueOrDefault(field, stored)
  if (typeof value === 'string') {
    return value === '' ? null : `${field.label}: ${cut(value)}`
  }
  if (field.type === 'inventory' && Array.isArray(value)) {
    const names = stacksOf(value).map((stack) => stackText(stack, economy?.gear ?? []))
    return `${field.label}: ${names.length === 0 ? '0' : summarizeItems(names)}`
  }
  if (field.type === 'conditions' && Array.isArray(value)) {
    const labels = markedLabels(field, value.filter(isText))
    return `${field.label}: ${labels.length === 0 ? 'none' : summarizeItems(labels)}`
  }
  if (Array.isArray(value)) {
    const items = value.filter(isText).filter((item) => item.trim() !== '')
    return `${field.label}: ${items.length === 0 ? '0' : summarizeItems(items)}`
  }
  if (typeof value === 'object') {
    return `${field.label}: ${String(value.current)}/${String(value.max)}`
  }
  return `${field.label}: ${withUnit(field, String(value), economy)}`
}
