/** Where a refused save of a field belongs in its dialog. */
export interface SheetFieldAnswer {
  /** The message of an input, keyed by the property the publisher named. */
  fields: Record<string, string>
  /** What belongs to no input: shown with the form, path and all. */
  form: string | null
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
]

/**
 * Reads the publisher's `path: message` for the field at `index` of the draft's sheet
 * (`sheet.fields[2].label: …`); a path inside a property, such as `roll.dice`, goes to that property's input. A path of another field or of the layout, or of a property
 * the form has no input for, comes back whole as a form message.
 */
export function sheetFieldAnswer(text: string, index: number): SheetFieldAnswer {
  const prefix = `sheet.fields[${String(index)}].`
  if (text.startsWith(prefix)) {
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

/** Where a refused save of the point budget belongs in its dialog. */
export interface SheetPointBudgetAnswer {
  /** The message of an input, keyed by the property the publisher named. */
  inputs: Partial<Record<'label' | 'total' | 'min' | 'max', string>>
  /** The message about the covered fields as a whole or one of them. */
  fields: string | null
  form: string | null
}

/**
 * Reads the publisher's `path: message` for the point budget: `sheet.pointBudget.<label|total|min|max>`
 * go to their inputs, `sheet.pointBudget.fields` and `.fields[i]` to the fields list. Everything
 * else comes back whole as a form message, including `sheet.pointBudget: …` (the budget as a whole).
 */
export function sheetPointBudgetAnswer(text: string): SheetPointBudgetAnswer {
  const input = /^sheet\.pointBudget\.(label|total|min|max): (.*)$/s.exec(text)
  if (input?.[1] !== undefined && input[2] !== undefined) {
    return { inputs: { [input[1]]: input[2] }, fields: null, form: null }
  }
  const fields = /^sheet\.pointBudget\.fields(?:\[\d+\])?: (.*)$/s.exec(text)
  if (fields?.[1] !== undefined) {
    return { inputs: {}, fields: fields[1], form: null }
  }
  return { inputs: {}, fields: null, form: text }
}
