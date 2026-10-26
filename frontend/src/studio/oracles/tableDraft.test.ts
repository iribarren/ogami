import { newEffect } from '../narrative/steps/effectDraft'
import type { OracleTable } from './tableDraft'
import type { EntryDraft, TableDraft } from './tableDraft'
import {
  coverage,
  diceRange,
  entrySummary,
  newEntry,
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

  it('reads the Scene Type and effects of an entry, and sends them back as stored', () => {
    const withExtras = {
      ...stored,
      entries: [
        {
          ...stored.entries[0],
          sceneType: 'calm',
          effects: [{ kind: 'endPhase', note: 'kept' }, { kind: 'odd' }],
        },
        stored.entries[1],
      ],
    } as OracleTable
    const draft = toDraft(withExtras)

    expect(draft.entries[0]).toMatchObject({ sceneType: 'calm' })
    expect(draft.entries[0]?.effects).toHaveLength(2)
    expect(toTable(withExtras, draft, 2)).toEqual(withExtras)
  })

  it('sets, changes and removes the Scene Type and the effects of an entry', () => {
    const draft = toDraft(stored)
    edit(draft, 1, { sceneType: 'quiet' })
    draft.entries = draft.entries.map((entry, at) =>
      at === 1 ? { ...entry, effects: [{ kind: 'endPhase' as const, extra: {} }] } : entry,
    )

    expect(toTable(stored, draft, 2).entries[1]).toMatchObject({
      sceneType: 'quiet',
      effects: [{ kind: 'endPhase' }],
    })
    edit(draft, 1, { sceneType: '' })
    draft.entries = draft.entries.map((entry) => ({ ...entry, effects: [] }))
    expect(toTable(stored, draft, 2).entries[1]).toEqual(stored.entries[1])
  })

  it('keeps an empty stored effects list, and a Scene Type or effects that are not readable', () => {
    const odd = {
      ...stored,
      entries: [
        { ...stored.entries[0], effects: [], sceneType: 7 },
        { ...stored.entries[1], effects: 'x' },
      ],
    } as unknown as OracleTable
    const draft = toDraft(odd)
    edit(draft, 0, { text: 'Fair' })

    const table = toTable(odd, draft, 2)

    expect(table.entries[0]).toEqual({ ...odd.entries[0], text: 'Fair' })
    expect(table.entries[1]).toEqual(odd.entries[1])
  })

  it('refuses an effect value that is not a whole number, by its entry and effect', () => {
    const draft = toDraft(stored)
    const effect = { ...newEffect('tracker'), value: { form: 'integer' as const, text: 'x' } }
    draft.entries = draft.entries.map((entry, at) =>
      at === 1 ? { ...entry, effects: [effect] } : entry,
    )
    const errors: Record<string, string> = {}

    toTable(stored, draft, 2, errors)

    expect(Object.keys(errors)).toEqual(['entries.1.effects.0.value'])
  })

  it('never sends the Scene Type or the effects of a schema version 1 draft', () => {
    const draft = toDraft(stored)
    edit(draft, 1, { sceneType: 'quiet' })

    expect(toTable(stored, draft, 1).entries[1]).toEqual(stored.entries[1])
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

describe('newEntry', () => {
  const table = (dice: string, entries: [number | string, number | string][]) =>
    toDraft({
      key: 'omens',
      name: 'Omens',
      dice,
      entries: entries.map(([min, max]) => ({ min, max, text: 'x' })),
    } as unknown as OracleTable)

  it('takes the total after the last max', () => {
    expect(
      newEntry(
        table('1d8', [
          [1, 3],
          [4, 5],
        ]),
        7,
      ),
    ).toMatchObject({ id: 7, min: '6', max: '6', weight: '' })
  })

  it('starts at the dice minimum, or 1 when the dice are unknown, without entries', () => {
    expect(newEntry(table('2d6', []), 0)).toMatchObject({ min: '2', max: '2' })
    expect(newEntry(table('2d6+1', []), 0)).toMatchObject({ min: '1', max: '1' })
  })

  it('leaves the range blank when the last max is not a whole number', () => {
    expect(newEntry(table('1d8', [[1, '']]), 0)).toMatchObject({ min: '', max: '' })
  })

  it('gives a weighted table weight 1', () => {
    const weighted = toDraft({
      key: 'mood',
      name: 'Mood',
      entries: [{ weight: 3, text: 'Calm' }],
    })
    expect(newEntry(weighted, 4)).toMatchObject({ id: 4, weight: '1', min: '', max: '' })
  })
})

describe('entrySummary', () => {
  it('is null for an entry without details', () => {
    expect(entrySummary(toEntryDraft({ text: 'x', key: 'k' }, 0))).toBeNull()
  })

  it('counts the Scene Type, effects and nested table', () => {
    const entry = toEntryDraft(
      {
        text: 'x',
        table: 'next',
        sceneType: 'chase',
        effects: [
          { kind: 'tracker', tracker: 'a', op: 'add', value: 1 },
          { kind: 'tracker', tracker: 'b', op: 'add', value: 1 },
        ],
      },
      0,
    )
    expect(entrySummary(entry)).toBe('Table next · 1 Scene Type · 2 effects')
    expect(
      entrySummary(
        toEntryDraft(
          { text: 'x', effects: [{ kind: 'tracker', tracker: 'a', op: 'add', value: 1 }] },
          0,
        ),
      ),
    ).toBe('1 effect')
  })
})
