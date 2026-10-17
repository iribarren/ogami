/**
 * The neutral shape of a sheet (ADR 0031): what the sheet renderer and the derived-value helpers
 * read. Play maps the API's pinned release into it, so Studio can render a draft sheet with the
 * same components.
 */

export interface SheetFieldView {
  key: string
  label: string
  hint: string | null
  /** The name of its layout group; without a layout, consecutive fields with the same group render together. */
  group: string | null
  type: 'number' | 'text' | 'list' | 'track' | 'derived'
  min: number | null
  max: number | null
  default: number | null
  multiline: boolean | null
  maxLength: number | null
  itemLabel: string | null
  maxItems: number | null
  formula: string | null
  /** How many columns of its group the field takes. */
  span: number
}

export interface SheetGroupView {
  name: string
  /** The columns of fields inside the group. */
  columns: number
  /** How many columns of the sheet the group takes. */
  span: number
}

export interface SheetLayoutView {
  /** The columns of groups the sheet has. */
  columns: number
  /** In declared order. */
  groups: SheetGroupView[]
}

export interface SheetTemplateView {
  fields: SheetFieldView[]
  /** Null when the release declares no groups. */
  layout: SheetLayoutView | null
}

/** What a Character stores under a field: a number, text, the rows of a list or a track. */
export type SheetValue = number | string | string[] | { current: number; max: number }

/** The value of a Tracker, which a derived field may read. */
export interface TrackerReading {
  key: string
  value: number
}
