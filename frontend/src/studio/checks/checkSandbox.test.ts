import type { SheetFieldView } from '@/shared/sheet/types'

import type { Sample } from '../sheet/useSheetSamples'
import type { BandDraft, DifficultyDraft } from './checkDraft'
import {
  burnScoreOf,
  diceBeatenBy,
  canBurnTestRoll,
  resolveSandbox,
  sampleDefaults,
  type SandboxState,
} from './checkSandbox'
import type { CheckTestRoll } from './useGameSystemChecks'

const field = (key: string, extra: Partial<SheetFieldView> = {}): SheetFieldView => ({
  key,
  label: key,
  hint: null,
  group: null,
  type: 'number',
  min: null,
  max: null,
  default: null,
  multiline: null,
  maxLength: null,
  itemLabel: null,
  maxItems: null,
  formula: null,
  span: 1,
  ...extra,
})

const template = [
  field('pow', { min: 0, max: 18 }),
  field('dex', { default: 10 }),
  field('hp', { type: 'track', max: 12 }),
  field('bonus', { type: 'derived', formula: '{pow} + 1' }),
]

const catalog = {
  fields: [
    { key: 'pow', label: 'Power', type: 'number' as const, max: null },
    { key: 'dex', label: 'Dexterity', type: 'number' as const, max: null },
    { key: 'hp', label: 'Hit points', type: 'track' as const, max: 12 },
    { key: 'bonus', label: 'Bonus', type: 'derived' as const, max: null },
  ],
  trackers: [{ key: 'heat', label: 'Heat', initial: 2 }],
}

const difficulty: DifficultyDraft = { label: 'Difficulty', default: '13', min: '9', max: '29' }

const band = (upTo: BandDraft['upTo']): BandDraft => ({ key: 'b', label: 'B', upTo })
const last = band({ kind: 'none' })

function state(changes: Partial<SandboxState> = {}): SandboxState {
  return {
    catalog,
    template,
    samples: {},
    trackerSamples: {},
    difficulty: null,
    difficultyText: null,
    situationalText: '0',
    bands: [last],
    ...changes,
  }
}

const typed = (value: string): Sample => ({ type: 'number', draft: value })

describe('the sandbox', () => {
  it('computes a literal, a tracker, a formula and a derived bound, and the rest for the last band', () => {
    const { bounds } = resolveSandbox(
      state({
        difficulty,
        samples: { pow: typed('7') },
        trackerSamples: { heat: '5' },
        bands: [
          band({ kind: 'number', value: '4' }),
          band({ kind: 'tracker', value: 'heat' }),
          band({ kind: 'formula', value: '{check:difficulty} - 1 + {pow}' }),
          band({ kind: 'formula', value: '{bonus} + 20' }),
          last,
        ],
      }),
    )

    expect(bounds).toEqual([
      { kind: 'value', value: 4 },
      { kind: 'value', value: 5 },
      { kind: 'value', value: 19 },
      { kind: 'value', value: 28 },
      { kind: 'rest' },
    ])
  })

  it('reads the defaults the server applies: a number its default, a track 0, a Tracker its initial value', () => {
    const { bounds } = resolveSandbox(
      state({
        bands: [
          band({ kind: 'formula', value: '{dex} + {hp} + {pow}' }),
          band({ kind: 'tracker', value: 'heat' }),
          last,
        ],
      }),
    )

    expect(bounds).toEqual([
      { kind: 'value', value: 10 },
      { kind: 'value', value: 2 },
      { kind: 'rest' },
    ])
  })

  it('holds a number’s default within a range that declares only a min or only a max', () => {
    const { bounds } = resolveSandbox(
      state({
        template: [field('pow', { min: 5, default: 2 }), field('dex', { max: 3, default: 8 })],
        bands: [
          band({ kind: 'formula', value: '{pow}' }),
          band({ kind: 'formula', value: '{dex}' }),
          last,
        ],
      }),
    )

    expect(bounds).toEqual([
      { kind: 'value', value: 5 },
      { kind: 'value', value: 3 },
      { kind: 'rest' },
    ])
  })

  it('reads a track’s max from the catalog', () => {
    const { bounds } = resolveSandbox(
      state({ bands: [band({ kind: 'formula', value: '{hp.max}' }), last] }),
    )

    expect(bounds[0]).toEqual({ kind: 'value', value: 12 })
  })

  it('has no bound it cannot compute: a failing formula, an unknown reference, a kept shape, no bound', () => {
    const { bounds } = resolveSandbox(
      state({
        bands: [
          band({ kind: 'formula', value: '1 / 0' }),
          band({ kind: 'formula', value: '{ghost}' }),
          band({ kind: 'formula', value: '1 +' }),
          band({ kind: 'tracker', value: 'gone' }),
          band({ kind: 'number', value: '4.5' }),
          band({ kind: 'unknown', raw: { odd: true } }),
          band({ kind: 'none' }),
          last,
        ],
      }),
    )

    expect(bounds.slice(0, 7).every(({ kind }) => kind === 'unknown')).toBe(true)
  })

  it('has no value for a number whose default is unknown while the sheet is not', () => {
    const { bounds } = resolveSandbox(
      state({ template: [], bands: [band({ kind: 'formula', value: '{dex}' }), last] }),
    )

    expect(bounds[0]).toEqual({ kind: 'unknown' })
  })

  it('does not compute a bound that reads the difficulty of a check without one', () => {
    const { bounds } = resolveSandbox(
      state({ bands: [band({ kind: 'formula', value: '{check:difficulty}' }), last] }),
    )

    expect(bounds[0]).toEqual({ kind: 'unknown' })
  })

  it('follows the difficulty typed, and the declared default while it is empty', () => {
    const bands = [band({ kind: 'formula', value: '{check:difficulty}' }), last]

    expect(resolveSandbox(state({ difficulty, bands })).bounds[0]).toEqual({
      kind: 'value',
      value: 13,
    })
    expect(resolveSandbox(state({ difficulty, bands, difficultyText: '20' })).bounds[0]).toEqual({
      kind: 'value',
      value: 20,
    })
    expect(resolveSandbox(state({ difficulty, bands, difficultyText: '' })).bounds[0]).toEqual({
      kind: 'value',
      value: 13,
    })
  })

  it('warns when the bounds that can be computed do not strictly increase', () => {
    const sandbox = resolveSandbox(
      state({
        bands: [
          band({ kind: 'number', value: '10' }),
          band({ kind: 'formula', value: '{ghost}' }),
          band({ kind: 'number', value: '10' }),
          last,
        ],
      }),
    )

    expect(sandbox.warning).toContain("Band 3's bound (10) is not above band 1's (10)")
    expect(
      resolveSandbox(
        state({
          bands: [
            band({ kind: 'number', value: '9' }),
            band({ kind: 'number', value: '10' }),
            last,
          ],
        }),
      ).warning,
    ).toBeNull()
  })

  it('sends only what was typed, so the server applies its defaults to the rest', () => {
    const none = resolveSandbox(state({ situationalText: '' }))
    expect(none.request).toEqual({ sheet: {}, trackers: {} })

    const some = resolveSandbox(
      state({
        difficulty,
        samples: {
          pow: typed('7'),
          hp: { type: 'track', draft: { current: '5', max: '12' } },
          dex: typed('  '),
        },
        trackerSamples: { heat: '3' },
        difficultyText: '15',
        situationalText: '-2',
      }),
    )
    expect(some.request).toEqual({
      sheet: { pow: 7, hp: 5 },
      trackers: { heat: 3 },
      difficulty: 15,
      situational: -2,
    })
  })

  it('ignores a sample typed for another type of the field', () => {
    const { request } = resolveSandbox(
      state({ samples: { pow: { type: 'text', draft: 'seven' } } }),
    )

    expect(request).toEqual({ sheet: {}, trackers: {}, situational: 0 })
  })

  it.each([
    [
      'a field that is not a whole number',
      { samples: { pow: typed('4.5') } },
      'Power',
      'Must be a whole number.',
    ],
    [
      'a number above its max',
      { samples: { pow: typed('19') } },
      'Power',
      'Must be between 0 and 18.',
    ],
    [
      'a track above its max',
      { samples: { hp: { type: 'track' as const, draft: { current: '13', max: '12' } } } },
      'Hit points',
      'Must be between 0 and 12.',
    ],
    [
      'a Tracker that is not a number',
      { trackerSamples: { heat: 'x' } },
      'Heat',
      'Must be a whole number.',
    ],
  ])('refuses %s and sends nothing', (_, changes, label, message) => {
    const sandbox = resolveSandbox(state(changes))

    expect(
      [...sandbox.fields, ...sandbox.trackers].find((input) => input.label === label)?.error,
    ).toBe(message)
    expect(sandbox.request).toBeNull()
  })

  it('refuses a difficulty outside its range and a situational outside -100..100', () => {
    const outside = resolveSandbox(state({ difficulty, difficultyText: '30' }))
    expect(outside.difficulty?.error).toBe('Must be between 9 and 29.')
    expect(outside.request).toBeNull()

    const situational = resolveSandbox(state({ situationalText: '101' }))
    expect(situational.situational.error).toBe('Must be between -100 and 100.')
    expect(situational.request).toBeNull()
    expect(resolveSandbox(state({ situationalText: 'a' })).request).toBeNull()
  })

  it('has no bound that reads a malformed sample', () => {
    const { bounds } = resolveSandbox(
      state({
        samples: { pow: typed('4.5') },
        bands: [
          band({ kind: 'formula', value: '{pow}' }),
          band({ kind: 'formula', value: '{bonus}' }),
          last,
        ],
      }),
    )

    expect(bounds.slice(0, 2)).toEqual([{ kind: 'unknown' }, { kind: 'unknown' }])
  })
})

describe('the pool size of a pool check', () => {
  const pool = (count: string) => ({ count, sides: '6', successAt: '5' })
  const sized = (count: string, changes: Partial<SandboxState> = {}) =>
    resolveSandbox(state({ pool: pool(count), ...changes })).poolSize

  it('has none for a dice check', () => {
    expect(resolveSandbox(state()).poolSize).toBeNull()
  })

  it('is an integer count, with the situational modifier added and held within 1..100', () => {
    expect(sized('4')).toBe(4)
    expect(sized('4', { situationalText: '-2' })).toBe(2)
    expect(sized('4', { situationalText: '-9' })).toBe(1)
    expect(sized('99', { situationalText: '5' })).toBe(100)
  })

  it('is a count formula read over the samples, sheet fields and a derived field included', () => {
    expect(sized('{pow} + {bonus}', { samples: { pow: typed('3') } })).toBe(7)
    expect(sized('{dex} - 4 + {tracker:heat}')).toBe(8)
  })

  it('has none while the formula cannot be computed or a sample is malformed', () => {
    expect(sized('{ghost} + 1')).toBeNull()
    expect(sized('{pow} +')).toBeNull()
    expect(sized('{pow} / 0')).toBeNull()
    expect(sized('{check:difficulty}', { difficulty })).toBeNull()
    expect(sized('{pow}', { samples: { pow: typed('4.5') } })).toBeNull()
    expect(sized('')).toBeNull()
  })
})

describe('the sandbox with a conditions field', () => {
  const conditions = [
    {
      key: 'wounds',
      label: 'Wounds',
      options: [
        { key: 'a', label: 'A' },
        { key: 'b', label: 'B' },
        { key: 'c', label: 'C' },
      ],
    },
  ]
  const withConditions = (changes: Partial<SandboxState> = {}) =>
    state({
      catalog: { ...catalog, conditions },
      template: [
        ...template,
        field('wounds', { type: 'conditions', options: conditions[0]?.options }),
      ],
      ...changes,
    })

  const sized = (count: string, changes: Partial<SandboxState>) =>
    resolveSandbox(withConditions({ pool: { count, sides: '6', successAt: '5' }, ...changes }))
      .poolSize

  it('lists it as a count from 0 to its options, sent only when typed', () => {
    const empty = resolveSandbox(withConditions())
    expect(empty.fields.find((input) => input.key === 'wounds')).toEqual({
      key: 'wounds',
      label: 'Wounds (marked, 0 to 3)',
      value: '',
      placeholder: '0',
      error: null,
    })
    expect(empty.request?.sheet).toEqual({})

    const typedTwo = resolveSandbox(withConditions({ conditionTexts: { wounds: '2' } }))
    expect(typedTwo.request?.sheet).toEqual({ wounds: 2 })
  })

  it('refuses a count outside 0..options or not whole, and sends nothing', () => {
    for (const text of ['4', '-1', '1.5', 'x']) {
      const sandbox = resolveSandbox(withConditions({ conditionTexts: { wounds: text } }))
      expect(sandbox.request).toBeNull()
      expect(sandbox.fields.find((input) => input.key === 'wounds')?.error).toBeTruthy()
    }
  })

  it('reads as the count in a pool count formula, a band bound and a derived field', () => {
    const marked = { wounds: '2' }
    expect(sized('{pow} + {wounds}', { conditionTexts: marked })).toBe(2)
    expect(sized('4 - {wounds}', { conditionTexts: marked })).toBe(2)

    const { bounds } = resolveSandbox(
      withConditions({
        conditionTexts: marked,
        bands: [band({ kind: 'formula', value: '{wounds} + 1' }), last],
      }),
    )
    expect(bounds[0]).toEqual({ kind: 'value', value: 3 })
  })
})

describe('sampleDefaults', () => {
  it('gives each number field the value the sandbox starts it at, held within its range', () => {
    expect(
      sampleDefaults([
        field('pow', { min: 2, max: 18 }),
        field('dex', { default: 10 }),
        field('low', { default: 1, min: 3 }),
        field('hp', { type: 'track', max: 12 }),
        field('bonus', { type: 'derived', formula: '{pow} + 1' }),
      ]),
    ).toEqual({ pow: 2, dex: 10, low: 3 })
  })
})

describe('the sample of a momentum', () => {
  const withMomentum = {
    ...catalog,
    fields: [
      ...catalog.fields,
      { key: 'momentum', label: 'Momentum', type: 'number' as const, max: null },
    ],
  }

  it('is an input of its own even when no formula names it, and is sent when typed', () => {
    const empty = resolveSandbox(state({ catalog: withMomentum }))
    expect(empty.fields.map(({ key }) => key)).toContain('momentum')
    expect(empty.request?.sheet).toEqual({})

    const typedIn = resolveSandbox(
      state({ catalog: withMomentum, samples: { momentum: typed('-3') } }),
    )
    expect(typedIn.request?.sheet).toEqual({ momentum: -3 })
  })

  it('has no difficulty for a challenge check: none is shown or sent', () => {
    const sandbox = resolveSandbox(state({ catalog: withMomentum, difficulty: null }))

    expect(sandbox.difficulty).toBeNull()
    expect(sandbox.request).not.toHaveProperty('difficulty')
  })
})

describe('the burn offer of a test roll', () => {
  const push = { label: 'Burn momentum', maxDice: null, rolledSpecial: null }
  const challenge = (changes: Partial<NonNullable<CheckTestRoll['challenge']>> = {}) => ({
    actionDie: 3,
    sides: 10,
    scoreMax: 10,
    score: 5,
    momentum: 9,
    momentumField: 'momentum',
    cancelled: false,
    dice: [
      { face: 4, beaten: true },
      { face: 8, beaten: false },
    ],
    burn: null,
    ...changes,
  })
  const roll = (
    changes: Partial<NonNullable<CheckTestRoll['challenge']>> = {},
    withPush = true,
  ) => ({ push: withPush ? push : null, challenge: challenge(changes) })

  it('is offered when the momentum beats more dice than the score', () => {
    expect(canBurnTestRoll(roll())).toBe(true)
  })

  it('is not offered when the momentum ties the die the score missed', () => {
    // Momentum 8 does not beat the 8: a tie goes to the die.
    expect(canBurnTestRoll(roll({ momentum: 8 }))).toBe(false)
  })

  it('is not offered when the momentum beats no more dice than the score', () => {
    expect(canBurnTestRoll(roll({ score: 9 }))).toBe(false)
    expect(canBurnTestRoll(roll({ momentum: 4 }))).toBe(false)
  })

  it('counts the challenge dice a score beats, a tie going to the die', () => {
    const dice = [{ face: 5 }, { face: 8 }, { face: 8 }]

    expect(diceBeatenBy(dice, 5)).toBe(0)
    expect(diceBeatenBy(dice, 6)).toBe(1)
    expect(diceBeatenBy(dice, 9)).toBe(3)
  })

  it('reads the momentum as the check caps it', () => {
    // Momentum 10 would beat the 8, but a cap of 8 ties it.
    expect(canBurnTestRoll(roll({ momentum: 10, scoreMax: 8 }))).toBe(false)
    expect(canBurnTestRoll(roll({ momentum: 10, scoreMax: 9 }))).toBe(true)
    expect(burnScoreOf(10, 9)).toBe(9)
    expect(burnScoreOf(7, 9)).toBe(7)
    expect(burnScoreOf(10, null)).toBe(10)
  })

  it('is not offered for a momentum at 0 or below', () => {
    expect(canBurnTestRoll(roll({ momentum: 0 }))).toBe(false)
    expect(canBurnTestRoll(roll({ momentum: -2 }))).toBe(false)
  })

  it('is not offered for a progress roll, a check with no push or a roll that was burned', () => {
    expect(canBurnTestRoll(roll({ actionDie: null, momentum: null, momentumField: null }))).toBe(
      false,
    )
    expect(canBurnTestRoll(roll({}, false))).toBe(false)
    expect(canBurnTestRoll(roll({ burn: { momentum: 9, score: 9 } }))).toBe(false)
  })

  it('is not offered for a dice or a pool check', () => {
    expect(canBurnTestRoll({ push, challenge: null })).toBe(false)
  })
})
