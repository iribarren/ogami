import { applyPaste, parsePaste, pasteStart } from './pasteEntries'
import { toDraft, type OracleTable, type TableDraft } from './tableDraft'

const ranged = (entries: [number, number][], dice = '1d8'): TableDraft =>
  toDraft({
    key: 'omens',
    name: 'Omens',
    dice,
    entries: entries.map(([min, max]) => ({ min, max, text: String(min) })),
  } as OracleTable)

/** The draft with an entry's max replaced, e.g. by text the editor cannot read. */
const withMax = (draft: TableDraft, max: string): TableDraft => ({
  ...draft,
  entries: draft.entries.map((entry, index) => (index === 0 ? { ...entry, max } : entry)),
})

const weighted = (): TableDraft =>
  toDraft({ key: 'mood', name: 'Mood', entries: [{ weight: 2, text: 'Calm' }] } as OracleTable)

describe('pasteStart', () => {
  it('follows the last max when adding, and the dice minimum when replacing', () => {
    const draft = ranged([[1, 4]], '2d6')
    expect(pasteStart(draft, 'append')).toBe(5)
    expect(pasteStart(draft, 'replace')).toBe(2)
  })

  it('starts at 1 when the dice are unknown, and has no start after an unreadable last max', () => {
    expect(pasteStart(ranged([[1, 4]], '2d6+1'), 'replace')).toBe(1)
    expect(pasteStart({ ...ranged([[1, 4]]), entries: [] }, 'append')).toBe(1)
    expect(pasteStart(withMax(ranged([[1, 4]]), ''), 'append')).toBeNull()
  })
})

describe('parsePaste', () => {
  it('numbers unnumbered lines one after another and ignores blank lines', () => {
    const { entries, errors } = parsePaste(ranged([]), 'Rain\n\n  \nSnow\nHail', 'replace')
    expect(errors).toEqual([])
    expect(entries).toEqual([
      { text: 'Rain', min: 1, max: 1 },
      { text: 'Snow', min: 2, max: 2 },
      { text: 'Hail', min: 3, max: 3 },
    ])
  })

  it.each([
    ['3 Rain', 3, 3, 'Rain'],
    ['3. Rain', 3, 3, 'Rain'],
    ['3: Rain', 3, 3, 'Rain'],
    ['3) Rain', 3, 3, 'Rain'],
    ['2-4 Rain', 2, 4, 'Rain'],
    ['2–4: Rain', 2, 4, 'Rain'],
    ['2 - 4) Rain', 2, 4, 'Rain'],
  ])('reads the range in %j', (line, min, max, text) => {
    expect(parsePaste(ranged([]), line, 'replace').entries).toEqual([{ text, min, max }])
  })

  it('does not read a decimal as a range with a dot separator', () => {
    const { entries } = parsePaste(ranged([]), '3.5 kg of salt\n2-4.5 litres', 'replace')
    expect(entries).toEqual([
      { text: '3.5 kg of salt', min: 1, max: 1 },
      { text: '2-4.5 litres', min: 2, max: 2 },
    ])
  })

  it('keeps a leading number in the text of a weighted table', () => {
    const weighted = { ...ranged([]), ranged: false }
    expect(parsePaste(weighted, '3.5 kg of salt', 'replace').entries).toEqual([
      { text: '3.5 kg of salt', weight: 1 },
    ])
  })

  it('continues from the last range of a numbered line', () => {
    const { entries } = parsePaste(ranged([]), '1-3 Rain\nSnow\n6 Hail\nFog', 'replace')
    expect(entries.map(({ min, max }) => [min, max])).toEqual([
      [1, 3],
      [4, 4],
      [6, 6],
      [7, 7],
    ])
  })

  it('starts after the last entry when adding', () => {
    const { entries } = parsePaste(ranged([[1, 5]]), 'Rain', 'append')
    expect(entries).toEqual([{ text: 'Rain', min: 6, max: 6 }])
  })

  it('keeps text that only starts with digits whole', () => {
    const { entries } = parsePaste(ranged([]), '2d6 damage\n12th Night\n7', 'replace')
    expect(entries.map(({ text }) => text)).toEqual(['2d6 damage', '12th Night', '7'])
  })

  it('gives weighted tables weight 1 and keeps a leading number in the text', () => {
    const { entries, errors } = parsePaste(weighted(), '1. Calm\n3-5 Stormy', 'append')
    expect(errors).toEqual([])
    expect(entries).toEqual([
      { text: '1. Calm', weight: 1 },
      { text: '3-5 Stormy', weight: 1 },
    ])
  })

  it('fails closed on an inverse range, naming its line', () => {
    const { entries, errors } = parsePaste(ranged([]), 'Rain\n\n5-3 Snow', 'replace')
    expect(entries).toEqual([])
    expect(errors).toEqual([
      { line: 3, message: 'Line 3: the range 5–3 has its min above its max.' },
    ])
  })

  it('fails closed on a range without text', () => {
    const { entries, errors } = parsePaste(ranged([]), '1-3:', 'replace')
    expect(entries).toEqual([])
    expect(errors[0]?.message).toBe('Line 1: add the entry text after the range.')
  })

  it('fails closed when there is no range to follow on from', () => {
    const { entries, errors } = parsePaste(
      withMax(ranged([[1, 4]]), 'x'),
      'Rain\n2 Snow\nFog',
      'append',
    )
    expect(entries).toEqual([])
    expect(errors).toEqual([
      { line: 1, message: 'Line 1: no range to follow on from; start the line with one like 1-3.' },
    ])
  })

  it('has no entries and no errors for an empty paste', () => {
    expect(parsePaste(ranged([]), ' \n\n', 'replace')).toEqual({ entries: [], errors: [] })
  })
})

describe('applyPaste', () => {
  it('replaces every entry or adds after the last, with fresh ids', () => {
    const draft = ranged([[1, 2]])
    const { entries } = parsePaste(draft, 'Rain\nSnow', 'append')

    const added = applyPaste(draft, entries, 'append', 10)
    expect(added.map(({ id, min, max, text }) => [id, min, max, text])).toEqual([
      [0, '1', '2', '1'],
      [10, '3', '3', 'Rain'],
      [11, '4', '4', 'Snow'],
    ])
    const replaced = applyPaste(draft, entries, 'replace', 10)
    expect(replaced.map(({ id, text }) => [id, text])).toEqual([
      [10, 'Rain'],
      [11, 'Snow'],
    ])
  })

  it('sets the weight of a weighted table, not a range', () => {
    const [entry] = applyPaste(weighted(), [{ text: 'Calm', weight: 1 }], 'replace', 4)
    expect(entry).toMatchObject({ id: 4, weight: '1', min: '', max: '' })
  })
})
