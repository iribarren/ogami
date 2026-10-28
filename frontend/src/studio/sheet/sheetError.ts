/** Where a refused save of a field belongs in its dialog. */
export interface SheetFieldAnswer {
  /** The message of an input, keyed by the property the publisher named. */
  fields: Record<string, string>
  /** What belongs to no input: shown with the form, path and all. */
  form: string | null
  /** The messages of an option's inputs, by its position; only when the publisher named one. */
  options?: Partial<Record<number, Partial<Record<'key' | 'label', string>>>>
}

/** The properties the dialog has an input for. */
const inputs = [
  'key',
  'label',
  'hint',
  'group',
  'span',
  'min',
  'max',
  'default',
  'multiline',
  'maxLength',
  'itemLabel',
  'maxItems',
  'formula',
  'roll',
  'startMax',
  'options',
  'capacity',
  'maxStacks',
  'categories',
]

/**
 * Reads the publisher's `path: message` for the field at `index` of the draft's sheet
 * (`sheet.fields[2].label: …`); a path inside a property, such as `roll.dice`, goes to that property's input. A path of another field or of the layout, or of a property
 * the form has no input for, comes back whole as a form message.
 */
export function sheetFieldAnswer(text: string, index: number): SheetFieldAnswer {
  const prefix = `sheet.fields[${String(index)}].`
  if (text.startsWith(prefix)) {
    const option = /^options\[(\d+)\]\.(key|label): (.*)$/s.exec(text.slice(prefix.length))
    const [, at, input, found] = option ?? []
    if (at !== undefined && input !== undefined && found !== undefined) {
      return { fields: {}, form: null, options: { [Number(at)]: { [input]: found } } }
    }
    const match = /^(\w+)(?:\.\w+)?: (.*)$/s.exec(text.slice(prefix.length))
    const [, property, message] = match ?? []
    if (property !== undefined && message !== undefined && inputs.includes(property)) {
      return { fields: { [property]: message }, form: null }
    }
  }
  return { fields: {}, form: text }
}

/** Where a refused save of the layout belongs in its dialog. */
export interface SheetLayoutAnswer {
  columns: string | null
  /** The messages of a group's inputs, keyed by the group's position in the request. */
  groups: Partial<Record<number, Partial<Record<'name' | 'columns' | 'span', string>>>>
  form: string | null
}

/**
 * Reads the publisher's `path: message` for the layout: `sheet.columns` and
 * `sheet.groups[i].<name|columns|span>` go to their inputs. Everything else comes back whole as a
 * form message, including `groups: …` (the request as a whole) and paths of the fields.
 */
export function sheetLayoutAnswer(text: string): SheetLayoutAnswer {
  const column = /^sheet\.columns: (.*)$/s.exec(text)
  if (column?.[1] !== undefined) {
    return { columns: column[1], groups: {}, form: null }
  }
  const group = /^sheet\.groups\[(\d+)\]\.(name|columns|span): (.*)$/s.exec(text)
  if (group?.[1] !== undefined && group[2] !== undefined && group[3] !== undefined) {
    return { columns: null, groups: { [Number(group[1])]: { [group[2]]: group[3] } }, form: null }
  }
  return { columns: null, groups: {}, form: text }
}

/** Where a refused save of a point budget belongs in its dialog. */
export interface SheetPointBudgetAnswer {
  /** The message of an input, keyed by the property the publisher named. */
  inputs: Partial<Record<'key' | 'label' | 'total' | 'min' | 'max', string>>
  /** The message about the covered fields as a whole or one of them. */
  fields: string | null
  form: string | null
}

/**
 * Reads the publisher's `path: message` for the budget at `index` of the draft's list:
 * `sheet.pointBudgets[i].<key|label|total|min|max>` go to their inputs, `.fields` and `.fields[j]`
 * to the fields list. Everything else comes back whole as a form message, including
 * `sheet.pointBudgets[i]: …` (the budget as a whole), the list as a whole and other budgets.
 */
export function sheetPointBudgetAnswer(text: string, index: number): SheetPointBudgetAnswer {
  const prefix = `sheet.pointBudgets[${String(index)}].`
  if (text.startsWith(prefix)) {
    const rest = text.slice(prefix.length)
    const input = /^(key|label|total|min|max): (.*)$/s.exec(rest)
    if (input?.[1] !== undefined && input[2] !== undefined) {
      return { inputs: { [input[1]]: input[2] }, fields: null, form: null }
    }
    const fields = /^fields(?:\[\d+\])?: (.*)$/s.exec(rest)
    if (fields?.[1] !== undefined) {
      return { inputs: {}, fields: fields[1], form: null }
    }
  }
  return { inputs: {}, fields: null, form: text }
}

/** Where a refused save of a gear entry belongs in its dialog. */
export interface GearAnswer {
  inputs: Partial<Record<'name' | 'cost' | 'slots' | 'category' | 'description', string>>
  form: string | null
}

/**
 * Reads the publisher's `path: message` for the entry at `index` of the draft's catalog:
 * `gear[i].<name|cost|slots|category|description>` go to their inputs. Everything else comes back
 * whole as a form message, including the catalog as a whole and other entries.
 */
export function gearAnswer(text: string, index: number): GearAnswer {
  const prefix = `gear[${String(index)}].`
  const input = text.startsWith(prefix)
    ? /^(name|cost|slots|category|description): (.*)$/s.exec(text.slice(prefix.length))
    : null
  return input?.[1] !== undefined && input[2] !== undefined
    ? { inputs: { [input[1]]: input[2] }, form: null }
    : { inputs: {}, form: text }
}

/** Where a refused save of the currency belongs in its form. */
export interface CurrencyAnswer {
  inputs: Partial<Record<'field' | 'unit', string>>
  form: string | null
}

/** Reads `sheet.currency.<field|unit>: message` into its input; anything else comes back whole. */
export function currencyAnswer(text: string): CurrencyAnswer {
  const input = /^sheet\.currency\.(field|unit): (.*)$/s.exec(text)
  return input?.[1] !== undefined && input[2] !== undefined
    ? { inputs: { [input[1]]: input[2] }, form: null }
    : { inputs: {}, form: text }
}
