import { edgeTracker } from '@/play/campaigns/fixtures'
import { computeDerived } from './derivedValues'
import { initiativeField, sheet } from '@/play/characters/fixtures'
import type { Draft } from './sheetValues'
import type { SheetFieldView } from './types'

const drafts: Record<string, Draft> = {
  reflexes: '7',
  body: '6',
  hp: { current: '30', max: '40' },
}

/** A derived field over the fixture sheet. */
const derived = (key: string, formula: string): SheetFieldView => ({
  ...initiativeField,
  key,
  label: key,
  type: 'derived',
  formula,
})

describe('computeDerived', () => {
  it('reads numbers, tracks, trackers and other derived values, in dependency order', () => {
    const fields = [
      derived('total', '{initiative} * 2'),
      derived('initiative', '{reflexes} + {body}'),
      derived('hp-left', '{hp.max} - {hp}'),
      derived('edgy', '{reflexes} + {tracker:edge}'),
      ...sheet,
    ]

    expect(computeDerived(fields, drafts, [edgeTracker])).toEqual({
      total: { value: 26 },
      initiative: { value: 13 },
      'hp-left': { value: 10 },
      edgy: { value: 8 },
    })
  })

  it('fails with a reason on division by zero, overflow and an unknown tracker, and so do dependents', () => {
    const fields = [
      ...sheet,
      derived('zero', '{body} / ({reflexes} - 7)'),
      derived('big', '{reflexes} * 1000000 * 1000'),
      derived('lost', '{tracker:nope}'),
      derived('after', '{zero} + 1'),
    ]

    expect(computeDerived(fields, drafts, [])).toEqual({
      zero: { reason: 'division by zero' },
      big: { reason: 'overflow' },
      lost: { reason: 'incomplete value' },
      after: { reason: 'depends on zero' },
    })
  })

  it('never reads a sheet field through a check reference', () => {
    const fields = [...sheet, derived('a', '{check:reflexes}'), derived('b', '{check:hp}')]

    expect(computeDerived(fields, drafts, [])).toEqual({
      a: { reason: 'incomplete value' },
      b: { reason: 'incomplete value' },
    })
  })

  it('is incomplete while a number or track draft is empty or not an integer', () => {
    const fields = [
      ...sheet,
      derived('a', '{reflexes} + 1'),
      derived('b', '{hp}'),
      derived('c', '{body}'),
    ]

    expect(
      computeDerived(
        fields,
        { ...drafts, reflexes: '', hp: { current: '1.5', max: '4' }, body: '1e2' },
        [],
      ),
    ).toEqual({
      a: { reason: 'incomplete value' },
      b: { reason: 'incomplete value' },
      c: { reason: 'incomplete value' },
    })
  })

  it('shows a formula that does not parse or reads text as failed, never throws', () => {
    const fields = [...sheet, derived('bad', '{reflexes} +'), derived('text', '{role}')]

    expect(computeDerived(fields, { ...drafts, role: 'Solo' }, [])).toEqual({
      bad: { reason: 'invalid formula' },
      text: { reason: 'incomplete value' },
    })
  })

  it('labels a dependency only when the reference itself is what failed', () => {
    const fields = [
      ...sheet,
      derived('broken', '{tracker:nope}'),
      derived('zero', '1 / 0 + {broken}'),
      derived('after', '{broken} + 1'),
    ]

    expect(computeDerived(fields, drafts, [])).toEqual({
      broken: { reason: 'incomplete value' },
      zero: { reason: 'division by zero' },
      after: { reason: 'depends on broken' },
    })
  })

  it('keeps every derived key accounted for on a cycle and next to an unparsable formula', () => {
    const fields = [
      ...sheet,
      derived('bad', '{reflexes} +'),
      derived('first', '{second}'),
      derived('second', '{first}'),
    ]

    expect(computeDerived(fields, drafts, [])).toEqual({
      bad: { reason: 'invalid formula' },
      first: { reason: 'invalid formula' },
      second: { reason: 'invalid formula' },
    })
  })

  it('reads a derived value that depends on an unparsable one as a failed dependency', () => {
    const fields = [...sheet, derived('bad', '{reflexes} +'), derived('after', '{bad} + 1')]

    expect(computeDerived(fields, drafts, [])).toEqual({
      bad: { reason: 'invalid formula' },
      after: { reason: 'depends on bad' },
    })
  })

  it('gives nothing for a sheet without derived fields', () => {
    expect(computeDerived(sheet, drafts, [])).toEqual({})
  })
})

describe('computeDerived over a conditions field', () => {
  const wounds: SheetFieldView = {
    ...initiativeField,
    key: 'wounds',
    label: 'Wounds',
    type: 'conditions',
    formula: null,
    options: [
      { key: 'hurt', label: 'Hurt' },
      { key: 'shaken', label: 'Shaken' },
    ],
  }

  it('reads the count marked as the draft holds it', () => {
    const fields = [wounds, derived('strain', '{wounds} * 2 + {reflexes}'), ...sheet]

    expect(computeDerived(fields, { ...drafts, wounds: ['hurt', 'shaken'] }, [])).toEqual({
      strain: { value: 11 },
    })
    expect(computeDerived(fields, { ...drafts, wounds: [] }, [])).toEqual({
      strain: { value: 7 },
    })
  })

  it('has no value without a draft, as the sheet never reads a missing field', () => {
    expect(computeDerived([wounds, derived('strain', '{wounds}')], {}, [])).toEqual({
      strain: { reason: 'incomplete value' },
    })
  })
})
