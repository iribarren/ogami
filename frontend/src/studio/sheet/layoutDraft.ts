import type { SheetGroup } from './sheetDraft'

export const maxSheetColumns = 2
export const maxGroupColumns = 4
export const maxGroups = 20
const maxNameLength = 60

/** The form state of one declared group. `previousName` is the stored name, none for a new group. */
export interface GroupDraft {
  /** Identifies the row while groups move, are added and removed; never sent. */
  id: number
  name: string
  previousName: string | null
  columns: number
  span: number
}

export interface LayoutDraft {
  columns: number
  groups: GroupDraft[]
}

export type GroupErrors = Partial<Record<'name' | 'columns' | 'span', string>>

/** A message per input; `form` is what belongs to no input. */
export interface LayoutErrors {
  columns: string | null
  form: string | null
  groups: Partial<Record<number, GroupErrors>>
}

export interface LayoutRequest {
  columns: number
  groups: { name: string; previousName?: string; columns: number; span: number }[]
}

let nextId = 0

const inRange = (value: unknown, max: number): value is number =>
  Number.isInteger(value) && (value as number) >= 1 && (value as number) <= max

/**
 * The stored layout as form state, or null when the sheet declares none or holds a value the form
 * cannot show (a count out of range, a group without a name): it is never guessed.
 */
export function readLayout(sheet: {
  columns: number
  groups: Partial<SheetGroup>[] | null
}): LayoutDraft | null {
  if (sheet.groups === null || !inRange(sheet.columns, maxSheetColumns)) {
    return null
  }
  const groups: GroupDraft[] = []
  for (const { name, columns, span } of sheet.groups) {
    if (
      typeof name !== 'string' ||
      !inRange(columns, maxGroupColumns) ||
      !inRange(span, maxSheetColumns)
    ) {
      return null
    }
    groups.push({ id: nextId++, name, previousName: name, columns, span })
  }
  return { columns: sheet.columns, groups }
}

export function addGroup(draft: LayoutDraft): LayoutDraft {
  const group: GroupDraft = { id: nextId++, name: '', previousName: null, columns: 1, span: 1 }
  return { ...draft, groups: [...draft.groups, group] }
}

/** The same draft, as is, at the edge of the list. */
export function moveGroup(
  draft: LayoutDraft,
  index: number,
  direction: 'up' | 'down',
): LayoutDraft {
  const target = direction === 'up' ? index - 1 : index + 1
  const group = draft.groups[index]
  const neighbour = draft.groups[target]
  if (group === undefined || neighbour === undefined) {
    return draft
  }
  const groups = [...draft.groups]
  groups[index] = neighbour
  groups[target] = group
  return { ...draft, groups }
}

export function removeGroup(draft: LayoutDraft, index: number): LayoutDraft {
  return { ...draft, groups: draft.groups.filter((_, at) => at !== index) }
}

/** How many fields still name the stored group; dropping a group that holds any is refused. */
export function fieldsHeldBy(group: GroupDraft, fields: { group?: unknown }[]): number {
  return group.previousName === null
    ? 0
    : fields.filter((field) => field.group === group.previousName).length
}

const range = (max: number) => (max === 1 ? 'Pick 1.' : `Pick 1 to ${String(max)}.`)

/**
 * The request to send, or what is wrong with the form. Names are trimmed and told apart by case,
 * as the publisher does; `previousName` is sent only for a group whose name changed.
 */
export function writeLayout(
  draft: LayoutDraft,
): { request: LayoutRequest } | { errors: LayoutErrors } {
  const errors: LayoutErrors = { columns: null, form: null, groups: {} }
  const flag = (index: number, property: keyof GroupErrors, message: string) => {
    errors.groups[index] = { ...errors.groups[index], [property]: message }
  }

  if (!inRange(draft.columns, maxSheetColumns)) {
    errors.columns = range(maxSheetColumns)
  }
  if (draft.groups.length === 0) {
    errors.form = 'Declare at least one group.'
  } else if (draft.groups.length > maxGroups) {
    errors.form = `A sheet has at most ${String(maxGroups)} groups.`
  }

  const seen = new Set<string>()
  const groups: LayoutRequest['groups'] = []
  draft.groups.forEach((group, index) => {
    const name = group.name.trim()
    if (name === '') {
      flag(index, 'name', 'Give the group a name.')
    } else if (name.length > maxNameLength) {
      flag(index, 'name', `At most ${String(maxNameLength)} characters.`)
    } else if (seen.has(name)) {
      flag(index, 'name', 'Another group has this name.')
    }
    seen.add(name)
    if (!inRange(group.columns, maxGroupColumns)) {
      flag(index, 'columns', range(maxGroupColumns))
    }
    if (!inRange(group.span, draft.columns)) {
      flag(index, 'span', range(draft.columns))
    }
    groups.push({
      name,
      ...(group.previousName !== null && group.previousName !== name
        ? { previousName: group.previousName }
        : {}),
      columns: group.columns,
      span: group.span,
    })
  })

  const flagged =
    errors.columns !== null || errors.form !== null || Object.keys(errors.groups).length > 0
  return flagged ? { errors } : { request: { columns: draft.columns, groups } }
}
