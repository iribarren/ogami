import type { SheetFieldView, SheetTemplateView } from '@/shared/sheet/types'

import { readLayout, writeLayout, type LayoutDraft } from './layoutDraft'
import { fieldTypes, type FieldType } from './sheetDraft'
import type { GameSystemSheet } from './useGameSystemSheet'

const text = (value: unknown) => (typeof value === 'string' ? value : null)
const count = (value: unknown) => (Number.isInteger(value) ? (value as number) : null)

/**
 * The sheet as the shared renderer reads it, from the stored read model, which holds whatever the
 * draft holds: a field that is not a keyed, labelled field of a known type, or repeats a key, is
 * left out, and a property of another shape reads as not set. A layout draft that passes the form's
 * own rules replaces the stored layout (fields follow a renamed group); otherwise the stored one
 * is shown, or none when it cannot be read. Nothing here throws.
 */
export function previewTemplate(
  sheet: GameSystemSheet,
  layoutDraft: LayoutDraft | null,
): SheetTemplateView {
  const written = layoutDraft === null ? null : writeLayout(layoutDraft)
  const request = written !== null && 'request' in written ? written.request : null
  const renames = new Map(
    request?.groups.flatMap(({ name, previousName }) =>
      previousName === undefined ? [] : [[previousName, name] as const],
    ),
  )

  const fields: SheetFieldView[] = []
  const seen = new Set<string>()
  for (const stored of sheet.fields) {
    const { key, label, type } = stored
    if (
      typeof key !== 'string' ||
      typeof label !== 'string' ||
      !fieldTypes.includes(type as FieldType) ||
      seen.has(key)
    ) {
      continue
    }
    seen.add(key)
    const group = text(stored.group)
    fields.push({
      key,
      label,
      hint: text(stored.hint),
      group: group === null ? null : (renames.get(group) ?? group),
      type: type as FieldType,
      min: count(stored.min),
      max: count(stored.max),
      default: count(stored.default),
      multiline: typeof stored.multiline === 'boolean' ? stored.multiline : null,
      maxLength: count(stored.maxLength),
      itemLabel: text(stored.itemLabel),
      maxItems: count(stored.maxItems),
      formula: text(stored.formula),
      span: count(stored.span) ?? 1,
    })
  }

  const layout = request ?? readLayout(sheet)
  return {
    fields,
    layout:
      layout === null
        ? null
        : {
            columns: layout.columns,
            groups: layout.groups.map(({ name, columns, span }) => ({ name, columns, span })),
          },
  }
}
