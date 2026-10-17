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
]

/**
 * Reads the publisher's `path: message` for the field at `index` of the draft's sheet
 * (`sheet.fields[2].label: …`). A path of another field or of the layout, or of a property
 * the form has no input for, comes back whole as a form message.
 */
export function sheetFieldAnswer(text: string, index: number): SheetFieldAnswer {
  const prefix = `sheet.fields[${String(index)}].`
  if (text.startsWith(prefix)) {
    const match = /^(\w+): (.*)$/s.exec(text.slice(prefix.length))
    const [, property, message] = match ?? []
    if (property !== undefined && message !== undefined && inputs.includes(property)) {
      return { fields: { [property]: message }, form: null }
    }
  }
  return { fields: {}, form: text }
}
