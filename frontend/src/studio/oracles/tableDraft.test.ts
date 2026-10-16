import type { OracleTable } from './tableDraft'
import type { EntryDraft, TableDraft } from './tableDraft'
import {
  coverage,
  diceRange,
  newTable,
  parseInteger,
  toDraft,
  toEntryDraft,
  toTable,
} from './tableDraft'

describe('diceRange', () => {
  it.each([
    ['1d6', { min: 1, max: 6 }],
    ['2d6', { min: 2, max: 12 }],
    ['d20', { min: 1, max: 20 }],
    ['d%', { min: 1, max: 100 }],
    [' 3D4 ', { min: 3, max: 12 }],
  ])('reads %s', (dice, range) => {
    expect(diceRange(dice)).toEqual(range)
  })

  it.each(['', '2d6+1', '4d6kh3', 'abc', '0d6', '1d0', '9999d9999'])(
    'does not know %j, so coverage is not computed',
    (dice) => {
      expect(diceRange(dice)).toBeNull()
    },
  )
})

describe('parseInteger', () => {
  it('accepts whole numbers only', () => {
    expect(parseInteger('12')).toBe(12)
    expect(parseInteger('-3')).toBe(-3)
    expect(parseInteger('')).toBeNull()
    expect(parseInteger('1.5')).toBeNull()
    expect(parseInteger('1e3')).toBeNull()
    expect(parseInteger(' 4')).toBeNull()
  })
})

describe('coverage', () => {
  const d6 = { min: 1, max: 6 }

  it('is complete when the entries tile the dice exactly', () => {
    const result = coverage(d6, [
      { min: 1, max: 2 },
      { min: 3, max: 6 },
    ])
    expect(result).toEqual({ gaps: [], overlaps: [], outside: [], invalid: [] })
  })

  it('lists every gap, including the ends', () => {
    const result = coverage(d6, [
      { min: 2, max: 2 },
      { min: 5, max: 5 },
    ])
    expect(result.gaps).toEqual([
      { from: 1, to: 1 },
      { from: 3, to: 4 },
      { from: 6, to: 6 },
    ])
  })

  it('lists every overlap', () => {
    const result = coverage(d6, [
      { min: 1, max: 4 },
      { min: 3, max: 6 },
      { min: 6, max: 6 },
    ])
    expect(result.overlaps).toEqual([
      { from: 3, to: 4 },
      { from: 6, to: 6 },
    ])
    expect(result.gaps).toEqual([])
  })

  it('flags entries outside the dice and counts only what is inside', () => {
    const result = coverage(d6, [
      { min: 0, max: 3 },
      { min: 4, max: 9 },
    ])
    expect(result.outside).toEqual([0, 1])
    expect(result.gaps).toEqual([])
    expect(result.overlaps).toEqual([])
  })

  it('flags incomplete and inverse ranges and leaves them out of the count', () => {
    const result = coverage(d6, [
      { min: null, max: 3 },
      { min: 5, max: 2 },
      { min: 1, max: 6 },
    ])
    expect(result.invalid).toEqual([0, 1])
    expect(result.gaps).toEqual([])
    expect(result.overlaps).toEqual([])
  })

  it('reports the whole range as a gap without entries', () => {
    expect(coverage({ min: 2, max: 12 }, []).gaps).toEqual([{ from: 2, to: 12 }])
  })
})

/** Edits one entry of a draft in place. */
function edit(draft: TableDraft, index: number, changes: Partial<EntryDraft>) {
  draft.entries = draft.entries.map((entry, at) =>
    at === index ? { ...entry, ...changes } : entry,
  )
}

describe('toTable', () => {
  const stored = {
    key: 'weather',
    name: 'Weather',
    dice: '1d6',
    source: 'srd',
    entries: [
      {
        min: 1,
        max: 3,
        text: 'Clear',
        key: 'clear',
        sceneType: 'calm',
        effects: [{ kind: 'x' }],
        note: 'n',
      },
      { min: 4, max: 6, text: 'Storm', table: 'storm-kind' },
    ],
  } as unknown as OracleTable

  it('round-trips an untouched draft, unknown fields included', () => {
    expect(toTable(stored, toDraft(stored), 2)).toEqual(stored)
  })

  it('changes only what was edited and keeps the unknown fields', () => {
    const draft = toDraft(stored)
    draft.name = 'Skies'
    edit(draft, 0, { text: 'Fair', max: '2' })

    const table = toTable(stored, draft, 2)

    expect(table).toMatchObject({ name: 'Skies', source: 'srd' })
    expect(table.entries[0]).toEqual({ ...stored.entries[0], text: 'Fair', max: 2 })
    expect(table.entries[1]).toEqual(stored.entries[1])
  })

  it('drops the dice and the ranges when the table becomes weighted', () => {
    const draft = toDraft(stored)
    draft.ranged = false
    draft.entries = draft.entries.map((entry) => ({ ...entry, weight: '2' }))

    const table = toTable(stored, draft, 2)

    expect(table).not.toHaveProperty('dice')
    expect(table.entries[1]).toEqual({ text: 'Storm', table: 'storm-kind', weight: 2 })
  })

  it('clears an emptied optional field instead of sending an empty string', () => {
    const draft = toDraft(stored)
    edit(draft, 1, { table: '', text: '' })

    expect(toTable(stored, draft, 2).entries[1]).toEqual({ min: 4, max: 6 })
  })

  it('leaves the entry key alone on a schema version 1 draft', () => {
    const draft = toDraft(stored)
    edit(draft, 0, { key: 'edited' })

    expect(toTable(stored, draft, 1).entries[0]).toHaveProperty('key', 'clear')
    expect(toTable(stored, draft, 2).entries[0]).toHaveProperty('key', 'edited')
  })

  it('builds a new entry without any v2 field', () => {
    const draft = toDraft(stored)
    draft.entries.push(toEntryDraft({ text: 'New', min: 7, max: 7 }, 99))

    expect(toTable(stored, draft, 1).entries[2]).toEqual({ text: 'New', min: 7, max: 7 })
  })
})

describe('newTable', () => {
  it('starts a ranged table with one entry covering all the dice', () => {
    expect(newTable('a', 'A', true, ' 2d6 ')).toEqual({
      key: 'a',
      name: 'A',
      dice: '2d6',
      entries: [{ min: 2, max: 12, text: 'New entry' }],
    })
  })

  it('starts a weighted table with one entry of weight 1', () => {
    expect(newTable('a', 'A', false, 'ignored')).toEqual({
      key: 'a',
      name: 'A',
      entries: [{ weight: 1, text: 'New entry' }],
    })
  })

  it('refuses dice it cannot read', () => {
    expect(newTable('a', 'A', true, '4d6kh3')).toBeNull()
  })
})
