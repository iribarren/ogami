import { applyPaste, parsePaste, pasteStart } from './pasteEntries'
import { toDraft, type TableDraft } from './tableDraft'

const ranged = (entries: [number, number][], dice = '1d8'): TableDraft =>
  toDraft({
    key: 'omens',
    name: 'Omens',
    dice,
    entries: entries.map(([min, max]) => ({ min, max, text: String(min) })),
  })

/** The draft with an entry's max replaced, e.g. by text the editor cannot read. */
const withMax = (draft: TableDraft, max: string): TableDraft => ({
  ...draft,
  entries: draft.entries.map((entry, index) => (index === 0 ? { ...entry, max } : entry)),
})

const weighted = (): TableDraft =>
  toDraft({ key: 'mood', name: 'Mood', entries: [{ weight: 2, text: 'Calm' }] })

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
    ['1.Rain', 1, 1, 'Rain'],
    ['1-3.Rain', 1, 3, 'Rain'],
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

  describe('entry keys', () => {
    const keyed = (draft: TableDraft, input: string, mode: 'replace' | 'append' = 'replace') =>
      parsePaste(draft, input, mode, true)

    it('reads an optional [key] before the text, with or without a range', () => {
      const { entries, errors } = keyed(
        ranged([]),
        '1-3 [rain] Heavy rain\n[snow] Snow\n5 [hail]Hail\nFog',
      )
      expect(errors).toEqual([])
      expect(entries).toEqual([
        { text: 'Heavy rain', min: 1, max: 3, key: 'rain' },
        { text: 'Snow', min: 4, max: 4, key: 'snow' },
        { text: 'Hail', min: 5, max: 5, key: 'hail' },
        { text: 'Fog', min: 6, max: 6 },
      ])
    })

    it('reads the key of a weighted table too', () => {
      expect(keyed({ ...weighted(), entries: [] }, '[calm] Calm\nGale').entries).toEqual([
        { text: 'Calm', weight: 1, key: 'calm' },
        { text: 'Gale', weight: 1 },
      ])
    })

    it.each(['[Rain] x', '[] x', '[ra in] x', '[rain x', `[${'a'.repeat(65)}] x`])(
      'fails the whole paste on a bracket that is not a valid key: %j',
      (line) => {
        const { entries, errors } = keyed(ranged([]), `Fine\n${line}`)
        expect(entries).toEqual([])
        expect(errors).toHaveLength(1)
        expect(errors[0]?.message).toMatch(
          /^Line 2: the text in brackets at the start is not a valid entry key/,
        )
      },
    )

    it('fails on a key without text', () => {
      expect(keyed(ranged([]), '1 [rain]').errors[0]?.message).toBe(
        'Line 1: add the entry text after the key.',
      )
    })

    it('keeps brackets as plain text when the schema version has no entry keys', () => {
      const { entries, errors } = parsePaste(ranged([]), '[rain] Rain\n2 [snow] Snow', 'replace')
      expect(errors).toEqual([])
      expect(entries).toEqual([
        { text: '[rain] Rain', min: 1, max: 1 },
        { text: '[snow] Snow', min: 2, max: 2 },
      ])
      expect(parsePaste(weighted(), '[x', 'replace').entries).toEqual([{ text: '[x', weight: 1 }])
    })

    it('reads an escaped bracket as a literal one and drops the backslash', () => {
      const { entries, errors } = keyed(ranged([]), '\\[sic] Rain\n2 \\[snow] Snow\n[a] A \\[b]')
      expect(errors).toEqual([])
      expect(entries).toEqual([
        { text: '[sic] Rain', min: 1, max: 1 },
        { text: '[snow] Snow', min: 2, max: 2 },
        { text: 'A \\[b]', min: 3, max: 3, key: 'a' },
      ])
      expect(keyed({ ...weighted(), entries: [] }, '\\[x').entries).toEqual([
        { text: '[x', weight: 1 },
      ])
    })

    it('still refuses an unescaped bracket that is no key, and says how to escape it', () => {
      const { entries, errors } = keyed(ranged([]), '[x')
      expect(entries).toEqual([])
      expect(errors[0]?.message).toContain('write \\[')
    })

    it('fails on a key repeated in the paste, naming both lines', () => {
      const { errors } = keyed(ranged([]), '[rain] A\nB\n[rain] C')
      expect(errors).toEqual([
        { line: 3, message: 'Line 3: the key "rain" is already used on line 1.' },
      ])
    })

    it('checks keys against the table only when adding', () => {
      const draft = ranged([[1, 2]])
      const table = { ...draft, entries: draft.entries.map((entry) => ({ ...entry, key: 'rain' })) }

      expect(keyed(table, '[rain] Again', 'append').errors).toEqual([
        { line: 1, message: 'Line 1: the key "rain" is already in the table.' },
      ])
      expect(keyed(table, '[rain] Again', 'replace').entries).toHaveLength(1)
    })

    it('puts the key into the draft, and leaves entries without one keyless', () => {
      const draft = ranged([[1, 2]])
      const { entries } = keyed(draft, '[rain] Rain\nSnow', 'append')
      expect(applyPaste(draft, entries, 'append', 10).map(({ key }) => key)).toEqual([
        '',
        'rain',
        '',
      ])
    })
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
