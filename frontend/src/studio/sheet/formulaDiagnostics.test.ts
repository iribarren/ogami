import type { SheetFieldView } from '@/shared/sheet/types'

import { formulaDiagnostics } from './formulaDiagnostics'

const field = (
  key: string,
  type: SheetFieldView['type'],
  formula: string | null = null,
): SheetFieldView => ({
  key,
  label: key,
  hint: null,
  group: null,
  type,
  min: null,
  max: null,
  default: null,
  multiline: null,
  maxLength: null,
  itemLabel: null,
  maxItems: null,
  formula,
  span: 1,
})

const fields = [
  field('pow', 'number'),
  field('hp', 'track'),
  field('notes', 'text'),
  field('items', 'list'),
  field('bonus', 'derived', '{pow} + 1'),
  field('other', 'derived', '{bonus} * 2'),
]
const drafts = { pow: '7', hp: { current: '5', max: '12' }, notes: '', items: [] }

/** The diagnostics of `formula` as the formula of `key`, over the fixture sheet. */
const diagnose = (formula: string, key = 'bonus', sheetFields = fields) =>
  formulaDiagnostics({
    key,
    formula,
    fields: sheetFields,
    drafts,
    trackerKeys: ['heat'],
    trackers: [{ key: 'heat', value: 3 }],
  })

describe('formula diagnostics', () => {
  it('computes the value over the sample values and Trackers', () => {
    expect(diagnose('{pow} * 2')).toEqual({ error: null, result: { value: 14 } })
    expect(diagnose('{hp} + {hp.max} + {tracker:heat}').result).toEqual({ value: 20 })
    expect(diagnose('{other}', 'fresh').result).toEqual({ value: 16 })
  })

  it('computes the typed formula instead of the stored one, also for a new field', () => {
    expect(diagnose('{pow}').result).toEqual({ value: 7 })
    expect(diagnose('{pow} - 1', 'fresh').result).toEqual({ value: 6 })
  })

  it('gives the reason when a value cannot be computed, without an error', () => {
    expect(diagnose('1 / 0')).toEqual({ error: null, result: { reason: 'division by zero' } })
    expect(
      formulaDiagnostics({
        key: 'bonus',
        formula: '{tracker:heat}',
        fields,
        drafts,
        trackerKeys: ['heat'],
        trackers: [],
      }),
    ).toEqual({ error: null, result: { reason: 'incomplete value' } })
  })

  it.each([
    ['1 + $', 5],
    ['{pow} +', undefined],
    ['', undefined],
    ['  {pow} + foo', 9],
  ])('reports the syntax error of "%s", with its position when it names one', (text, position) => {
    const { error, result } = diagnose(text)

    expect(error?.position).toBe(position)
    expect(error?.message).toBeTruthy()
    expect(result).toBeNull()
  })

  it.each([
    ['{pow} + {ghost}', '{ghost}: unknown field "ghost"', 9],
    [
      '{notes}',
      '{notes}: field "notes" is a text field, a formula reads number, track and derived fields only',
      1,
    ],
    [
      '1 + {items}',
      '{items}: field "items" is a list field, a formula reads number, track and derived fields only',
      5,
    ],
    ['{pow.max}', '{pow.max}: field "pow" is a number field, only a track field has a max', 1],
    ['2 * {tracker:ghost}', '{tracker:ghost}: unknown tracker "ghost"', 5],
    [
      '{check:difficulty}',
      '{check:difficulty}: a check reference is read only by the bands of a check',
      1,
    ],
  ])('refuses %s as the publisher does', (text, message, position) => {
    expect(diagnose(text)).toEqual({ error: { message, position }, result: null })
  })

  it('reports the first refused reference, in order of appearance', () => {
    expect(diagnose('{ghost} + {notes}').error?.message).toBe('{ghost}: unknown field "ghost"')
  })

  it('reports a cycle through the field, at the reference that closes it', () => {
    expect(diagnose('{pow} + {other}')).toEqual({
      error: {
        message: 'Derived values depend on each other in a cycle: bonus -> other -> bonus.',
        position: 9,
      },
      result: null,
    })
  })

  it('reports a field that reads itself', () => {
    expect(diagnose('{bonus} + 1')).toMatchObject({
      error: {
        message: 'Derived values depend on each other in a cycle: bonus -> bonus.',
        position: 1,
      },
      result: null,
    })
  })

  it('does not blame the field for a cycle elsewhere or for another formula that is invalid', () => {
    const elsewhere = [
      ...fields.filter((held) => held.key !== 'bonus' && held.key !== 'other'),
      field('x', 'derived', '{y}'),
      field('y', 'derived', '{x}'),
      field('z', 'derived', '1 +'),
    ]

    expect(diagnose('{pow}', 'bonus', elsewhere).error).toBeNull()
  })
})
