import { oracles } from '../fixtures'
import {
  chaosRange,
  draftProblems,
  toDraft,
  toLevelDraft,
  toOracle,
  trackerEditable,
  type LevelDraft,
  type LikelihoodDraft,
} from './likelihoodDraft'
import type { LikelihoodOracle } from './useGameSystemOracles'

const [fate] = oracles.likelihood as [(typeof oracles.likelihood)[number]]
const stored: LikelihoodOracle = fate.definition

/** An oracle with everything: chaos (and its tracker), exceptional bands, a random event, and an unknown field. */
const full: LikelihoodOracle = {
  ...stored,
  chaos: { min: 1, max: 9, neutral: 5, shiftPerPoint: 5, tracker: 'chaos', extra: 1 },
  exceptionalPercent: 20,
  randomEvent: { table: 'weather', note: 'x' },
  note: 'kept',
} as LikelihoodOracle

const [level] = toDraft(full).levels as [LevelDraft, ...LevelDraft[]]

const edit = (draft: LikelihoodDraft, changes: Partial<LikelihoodDraft>) => ({
  ...draft,
  ...changes,
})

describe('toDraft and toOracle', () => {
  it('round-trips an oracle unchanged, unknown fields and chaos tracker included', () => {
    expect(toOracle(full, toDraft(full), 2)).toEqual(full)
    expect(toOracle(stored, toDraft(stored), 2)).toEqual(stored)
  })

  it('keeps a stored null as it is while the field stays empty', () => {
    const withNulls = { ...stored, chaos: null, exceptionalPercent: null, randomEvent: null }

    expect(toOracle(withNulls, toDraft(withNulls), 2)).toEqual(withNulls)
  })

  it('lays the edits over the stored oracle', () => {
    const draft = toDraft(full)
    const oracle = toOracle(
      full,
      edit(draft, {
        key: 'luck',
        name: 'Luck',
        sides: '20',
        exceptionalPercent: '',
        randomEvent: '',
        levels: [{ ...level, target: '12' }],
        chaos: { min: '2', max: '8', neutral: '4', shiftPerPoint: '1', tracker: 'chaos' },
      }),
      2,
    )

    expect(oracle).toEqual({
      key: 'luck',
      name: 'Luck',
      sides: 20,
      levels: [{ key: 'even', label: '50/50', target: 12 }],
      chaos: { min: 2, max: 8, neutral: 4, shiftPerPoint: 1, tracker: 'chaos', extra: 1 },
      note: 'kept',
    })
  })

  it('drops the chaos when it is switched off, and sends the random event table', () => {
    const draft = toDraft(full)

    expect(
      toOracle(full, edit(draft, { chaos: null, randomEvent: 'storm-kind' }), 2),
    ).toMatchObject({
      randomEvent: { table: 'storm-kind', note: 'x' },
    })
    expect(toOracle(full, edit(draft, { chaos: null }), 2)).not.toHaveProperty('chaos')
  })

  it('never sends the schema version 2 fields of a version 1 draft', () => {
    const oracle = toOracle(full, toDraft(full), 1)

    expect(oracle).not.toHaveProperty('randomEvent')
    expect(oracle.chaos).toEqual({ min: 1, max: 9, neutral: 5, shiftPerPoint: 5, extra: 1 })
  })

  it('picks, changes and removes the chaos tracker in a version 2 draft', () => {
    const draft = toDraft(full)
    const withTracker = (tracker: string) =>
      toOracle(
        full,
        edit(draft, { chaos: { min: '1', max: '9', neutral: '5', shiftPerPoint: '5', tracker } }),
        2,
      ).chaos

    expect(withTracker('heat')).toEqual({ ...full.chaos, tracker: 'heat' })
    expect(withTracker('')).not.toHaveProperty('tracker')
    expect(withTracker('')).toHaveProperty('extra', 1)
  })

  it('keeps a chaos tracker that is not text as stored', () => {
    const odd = { ...full, chaos: { ...full.chaos, tracker: 42 } } as unknown as LikelihoodOracle

    expect(toDraft(odd).chaos?.tracker).toBe('')
    expect(toOracle(odd, toDraft(odd), 2)).toEqual(odd)
    expect(trackerEditable(odd)).toBe(false)
  })

  it('numbers an added level after the stored ones', () => {
    expect(toLevelDraft({}, 2)).toMatchObject({ id: 2, key: '', label: '', target: '' })
  })
})

describe('draftProblems', () => {
  const draft = toDraft(full)

  it('accepts a stored oracle', () => {
    expect(draftProblems(draft)).toEqual([])
  })

  it.each([
    ['key', { key: ' ' }],
    ['name', { name: '' }],
    ['sides', { sides: '1.5' }],
    ['exceptional percent', { exceptionalPercent: 'x' }],
    ['chaos', { chaos: { min: '1', max: '', neutral: '5', shiftPerPoint: '5', tracker: '' } }],
    ['levels', { levels: [{ ...level, target: '5.5' }] }],
    ['level key', { levels: [{ ...level, key: '' }] }],
    ['level label', { levels: [{ ...level, label: ' ' }] }],
  ])('refuses an unreadable or empty %s', (_field, changes) => {
    expect(draftProblems(edit(draft, changes))).not.toEqual([])
  })

  it('refuses duplicate level keys', () => {
    const first = level
    expect(draftProblems(edit(draft, { levels: [first, { ...first, id: 9 }] }))).toContain(
      'Level keys must be unique.',
    )
  })
})

describe('chaosRange', () => {
  it('is the readable min and max, or null without chaos or with an unreadable bound', () => {
    const draft = toDraft(full)

    expect(chaosRange(draft)).toEqual({ min: 1, max: 9 })
    expect(chaosRange(edit(draft, { chaos: null }))).toBeNull()
    expect(
      chaosRange(
        edit(draft, {
          chaos: { min: 'a', max: '9', neutral: '5', shiftPerPoint: '5', tracker: '' },
        }),
      ),
    ).toBeNull()
  })
})
