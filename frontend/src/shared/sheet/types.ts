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
  type: 'number' | 'text' | 'list' | 'track' | 'conditions' | 'inventory' | 'derived'
  min: number | null
  max: number | null
  default: number | null
  multiline: boolean | null
  maxLength: number | null
  itemLabel: string | null
  maxItems: number | null
  formula: string | null
  /** Number: the dice expression the server rolls when a Character is created (ADR 0040); absent reads as none. */
  roll?: string | null
  /** Track: the formula it starts full at when a Character is created (ADR 0040). */
  startMax?: string | null
  /** How many columns of its group the field takes. */
  span: number
  /** Conditions: the options a Character can be marked with, in declared order (ADR 0041). */
  options?: { key: string; label: string }[] | null
  /** Inventory: the slots its stacks may use together; null for no limit (ADR 0045). */
  capacity?: number | null
  /** Inventory: the most stacks it holds. */
  maxStacks?: number | null
  /** Inventory: the gear categories it accepts; null for every entry. */
  categories?: string[] | null
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

/** One entry of the release's gear catalog (ADR 0045): what an inventory stack of it takes. */
export interface SheetGear {
  key: string
  name: string
  /** Slots one unit takes. */
  slots: number
  category: string | null
}

/** The sheet number field money is kept in, and what follows an amount ("eb"). */
export interface SheetCurrency {
  field: string
  unit: string | null
}

/** What inventories and money need from the release beyond the fields (ADR 0045). */
export interface SheetEconomy {
  gear: SheetGear[]
  currency: SheetCurrency | null
}

export interface SheetTemplateView {
  fields: SheetFieldView[]
  /** Null when the release declares no groups. */
  layout: SheetLayoutView | null
  /** The gear catalog and the currency; Studio's sheet builder has none yet, and its inventories show no catalog. */
  economy?: SheetEconomy
}

/** A stack of an inventory (ADR 0045): catalog gear by key, or a free stack with its own name and slots. */
export type SheetStack =
  { gear: string; quantity: number } | { name: string; quantity: number; slots: number }

/** What a Character stores under a field: a number, text, the rows of a list, the marked option keys of a conditions field, the stacks of an inventory or a track. */
export type SheetValue =
  number | string | string[] | SheetStack[] | { current: number; max: number }

/** The value of a Tracker, which a derived field may read. */
export interface TrackerReading {
  key: string
  value: number
}
