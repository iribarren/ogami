import { readdirSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { readRelease } from './release'
import { miniRelease, step } from './testRelease'

// Relative to this file, so the test does not depend on where Vitest runs.
const presets = resolve(import.meta.dirname, '../../../../backend/presets')

describe('readRelease', () => {
  it('reads what printing needs from a release', () => {
    const release = readRelease(miniRelease())

    expect(release.systemName).toBe('Mini')
    expect(release.license).toBe('CC BY-SA 4.0')
    expect(release.trackers.map((tracker) => [tracker.key, tracker.kind, tracker.size])).toEqual([
      ['doom', 'clock', 6],
      ['nerve', 'counter', 5],
    ])
    expect(release.factSlots).toEqual([{ key: 'sin', label: 'The Sin', hint: 'What was done.' }])
    expect(release.likelihoods).toEqual(['yes-no'])
    expect(release.tables[0]?.entries[0]).toMatchObject({
      text: 'One',
      weight: 3,
      sceneType: 'scene',
    })
    expect(release.sceneTypes[0]?.setup[0]).toMatchObject({
      key: 'ask',
      kind: 'prompt',
      options: [],
    })
    expect(release.flows[0]).toMatchObject({
      key: 'mini',
      oracles: ['yes-no', 'luck'],
      selectionTables: ['deck'],
    })
  })

  it('reads every preset file of the backend', () => {
    const files = readdirSync(presets).filter((name) => name.endsWith('.json'))

    expect(files.length).toBeGreaterThan(5)
    for (const name of files) {
      expect(() =>
        readRelease(JSON.parse(readFileSync(resolve(presets, name), 'utf8'))),
      ).not.toThrow()
    }
  })

  it('reads a release with none of the optional parts', () => {
    const raw = miniRelease()
    const bare = {
      gameSystem: { name: raw.gameSystem.name },
      trackers: [],
      factSlots: [],
      oracles: { tables: [], likelihood: [] },
      sceneTypes: [],
      flows: [{ key: 'f', name: 'F', oracles: [], trackers: [] }],
    }

    expect(readRelease(bare)).toMatchObject({
      license: undefined,
      tags: [],
      flows: [{ description: undefined, selectionTables: [] }],
    })
  })

  it('reads the tags, the ranges of a ranged table and the bands of a check', () => {
    const raw = miniRelease([
      step('roll', 'check', {
        outcomes: [
          { band: 'done', effects: [{ kind: 'tracker', tracker: 'doom', op: 'add', value: 1 }] },
          {
            special: 'critical',
            effects: [
              { kind: 'createNpc' },
              { kind: 'sheet', field: 'x', op: 'add', value: { formula: 'a' } },
            ],
          },
        ],
      }),
    ])
    Object.assign(raw, { tags: [{ key: 'crew', label: 'Crew' }] })
    raw.oracles.tables[1]?.entries.splice(0, 2, { text: 'Low', min: 1, max: 3 } as never)

    const release = readRelease(raw)

    expect(release.tags).toEqual([{ key: 'crew', label: 'Crew' }])
    expect(release.tables[1]?.entries[0]).toMatchObject({ min: 1, max: 3 })
    expect(release.sceneTypes[0]?.setup[0]?.outcomes).toEqual([
      { band: 'done', effects: [{ kind: 'tracker', tracker: 'doom', op: 'add', value: 1 }] },
      {
        band: 'critical',
        effects: [
          { kind: 'other', name: 'createNpc' },
          { kind: 'other', name: 'sheet' },
        ],
      },
    ])
  })

  describe('what the checks reference, the trackers sheet and the campaign log print', () => {
    it('reads the checks with their hint, dice and band labels in order', () => {
      expect(readRelease(miniRelease()).checks).toEqual([
        {
          key: 'risk',
          label: 'Risk roll',
          hint: 'Roll a d6: high is good.',
          dice: '1d6',
          bands: [
            { key: 'wrong', label: 'Goes wrong' },
            { key: 'done', label: 'Done' },
          ],
        },
      ])
    })

    it('reads a check without dice, and a release without checks, as having none', () => {
      const raw = miniRelease()
      Object.assign(raw.checks[0] ?? {}, { dice: undefined, pool: { count: 2, sides: 6 } })
      const bare = miniRelease()
      Object.assign(bare, { checks: undefined })

      expect(readRelease(raw).checks[0]?.dice).toBeUndefined()
      expect(readRelease(bare).checks).toEqual([])
    })

    it('reads the likelihood oracles in full, and keeps their keys as `likelihoods`', () => {
      const release = readRelease(miniRelease())

      expect(release.likelihoods).toEqual(['yes-no'])
      expect(release.likelihoodOracles).toEqual([
        {
          key: 'yes-no',
          name: 'Yes/no question',
          sides: 100,
          levels: [
            { key: 'unlikely', label: 'Unlikely', target: 30 },
            { key: 'likely', label: 'Likely', target: 70 },
          ],
          exceptionalPercent: 10,
        },
      ])
    })

    it('reads an exceptional percent of 0', () => {
      const raw = miniRelease()
      Object.assign(raw.oracles.likelihood[0] ?? {}, { exceptionalPercent: 0 })

      expect(readRelease(raw).likelihoodOracles[0]?.exceptionalPercent).toBe(0)
    })

    it('reads a likelihood oracle without an exceptional percent', () => {
      const raw = miniRelease()
      Object.assign(raw.oracles.likelihood[0] ?? {}, { exceptionalPercent: undefined })

      expect(readRelease(raw).likelihoodOracles[0]?.exceptionalPercent).toBeUndefined()
    })

    it('reads the starting value of a tracker, and the hint of a Fact slot and a Tag', () => {
      const release = readRelease(miniRelease())

      expect(release.trackers.map((tracker) => [tracker.key, tracker.initial])).toEqual([
        ['doom', undefined],
        ['nerve', 2],
      ])
      expect(release.trackers[1]).toMatchObject({ size: 5, min: 0 })
      expect(release.factSlots[0]?.hint).toBe('What was done.')
      expect(release.tags).toEqual([{ key: 'ally', label: 'Ally', hint: 'Someone on your side.' }])
    })

    it('reads a Fact slot and a Tag without a hint', () => {
      const raw = miniRelease()
      Object.assign(raw.factSlots[0] ?? {}, { hint: undefined })
      Object.assign(raw.tags[0] ?? {}, { hint: undefined })
      const release = readRelease(raw)

      expect(release.factSlots[0]?.hint).toBeUndefined()
      expect(release.tags[0]?.hint).toBeUndefined()
    })

    it('reads a schema version 1 preset, which has no trackers, checks of its own or tags', () => {
      const raw = JSON.parse(readFileSync(resolve(presets, 'free-journal.json'), 'utf8')) as object

      expect(readRelease(raw)).toMatchObject({ trackers: [], tags: [], factSlots: [] })
    })

    it.each<[string, (raw: ReturnType<typeof miniRelease>) => void, RegExp]>([
      [
        'a check without a label',
        (raw) => Object.assign(raw.checks[0] ?? {}, { label: '' }),
        /checks\[0\]\.label/,
      ],
      [
        'a check without a hint',
        (raw) => Object.assign(raw.checks[0] ?? {}, { hint: undefined }),
        /checks\[0\]\.hint/,
      ],
      [
        'a check whose dice are not text',
        (raw) => Object.assign(raw.checks[0] ?? {}, { dice: 6 }),
        /checks\[0\]\.dice/,
      ],
      [
        'a check without bands',
        (raw) => Object.assign(raw.checks[0] ?? {}, { bands: undefined }),
        /checks\[0\]\.bands: expected a list/,
      ],
      [
        'a band without a label',
        (raw) => Object.assign(raw.checks[0]?.bands[1] ?? {}, { label: undefined }),
        /bands\[1\]\.label/,
      ],
      [
        'checks that are not a list',
        (raw) => Object.assign(raw, { checks: {} }),
        /file\.checks: expected a list/,
      ],
      [
        'a likelihood oracle without a name',
        (raw) => Object.assign(raw.oracles.likelihood[0] ?? {}, { name: undefined }),
        /likelihood\[0\]\.name/,
      ],
      [
        'a likelihood oracle without sides',
        (raw) => Object.assign(raw.oracles.likelihood[0] ?? {}, { sides: 'x' }),
        /likelihood\[0\]\.sides: expected a number/,
      ],
      [
        'a likelihood oracle with a fractional die',
        (raw) => Object.assign(raw.oracles.likelihood[0] ?? {}, { sides: 10.5 }),
        /likelihood\[0\]\.sides: expected a whole number/,
      ],
      [
        'a likelihood oracle without levels',
        (raw) => Object.assign(raw.oracles.likelihood[0] ?? {}, { levels: undefined }),
        /likelihood\[0\]\.levels: expected a list/,
      ],
      [
        'a level without a target',
        (raw) => Object.assign(raw.oracles.likelihood[0]?.levels[1] ?? {}, { target: '70' }),
        /levels\[1\]\.target/,
      ],
      [
        'a level with a target above the die',
        (raw) => Object.assign(raw.oracles.likelihood[0]?.levels[1] ?? {}, { target: 101 }),
        /levels\[1\]\.target: expected a whole number from 0 to 100/,
      ],
      [
        'an exceptional percent that is not a number',
        (raw) => Object.assign(raw.oracles.likelihood[0] ?? {}, { exceptionalPercent: 'x' }),
        /exceptionalPercent: expected a number/,
      ],
      [
        'an exceptional percent below 0',
        (raw) => Object.assign(raw.oracles.likelihood[0] ?? {}, { exceptionalPercent: -1 }),
        /exceptionalPercent: expected a whole number from 0 to 100\./,
      ],
      [
        'an exceptional percent above 100',
        (raw) => Object.assign(raw.oracles.likelihood[0] ?? {}, { exceptionalPercent: 101 }),
        /exceptionalPercent: expected a whole number from 0 to 100\./,
      ],
      [
        'a tracker start that is not a number',
        (raw) => Object.assign(raw.trackers[1] ?? {}, { initial: 'x' }),
        /trackers\[1\]\.initial: expected a number/,
      ],
      [
        'a Fact slot hint that is empty',
        (raw) => Object.assign(raw.factSlots[0] ?? {}, { hint: '' }),
        /factSlots\[0\]\.hint/,
      ],
      [
        'a Tag hint that is not text',
        (raw) => Object.assign(raw.tags[0] ?? {}, { hint: 4 }),
        /tags\[0\]\.hint/,
      ],
    ])('throws on %s, naming where', (_name, break_, message) => {
      const raw = miniRelease()
      break_(raw)

      expect(() => readRelease(raw)).toThrow(message)
    })
  })

  it('keeps a step kind it does not know, for the card to degrade', () => {
    const release = readRelease(miniRelease([step('odd', 'teleport')]))

    expect(release.sceneTypes[0]?.setup[0]?.kind).toBe('teleport')
  })

  it.each<[string, (raw: ReturnType<typeof miniRelease>) => void, RegExp]>([
    ['a release that is not an object', () => undefined, /file/],
    [
      'a missing system name',
      (raw) => Object.assign(raw.gameSystem, { name: '' }),
      /gameSystem\.name/,
    ],
    [
      'a list that is not a list',
      (raw) => Object.assign(raw, { sceneTypes: {} }),
      /file\.sceneTypes: expected a list/,
    ],
    [
      'a tracker of an unknown kind',
      (raw) => Object.assign(raw.trackers[0] ?? {}, { kind: 'dial' }),
      /trackers\[0\]\.kind/,
    ],
    [
      'a tracker without its size',
      (raw) => Object.assign(raw.trackers[1] ?? {}, { max: 'x' }),
      /trackers\[1\]\.max/,
    ],
    [
      'a weight that is not whole',
      (raw) => Object.assign(raw.oracles.tables[0]?.entries[0] ?? {}, { weight: 1.5 }),
      /tables\[0\]\.entries\[0\]\.weight: expected a whole number of at least 1\./,
    ],
    [
      'a weight of zero',
      (raw) => Object.assign(raw.oracles.tables[0]?.entries[0] ?? {}, { weight: 0 }),
      /tables\[0\]\.entries\[0\]\.weight: expected a whole number of at least 1/,
    ],
    [
      'a negative weight',
      (raw) => Object.assign(raw.oracles.tables[0]?.entries[0] ?? {}, { weight: -2 }),
      /tables\[0\]\.entries\[0\]\.weight: expected a whole number of at least 1/,
    ],
    [
      'a range that runs backwards',
      (raw) => Object.assign(raw.oracles.tables[1]?.entries[0] ?? {}, { min: 4, max: 2 }),
      /entries\[0\]: "min" is above "max"/,
    ],
    [
      'a range with only one end',
      (raw) => Object.assign(raw.oracles.tables[1]?.entries[0] ?? {}, { min: 4 }),
      /entries\[0\]\.max/,
    ],
    [
      'a step without a title',
      (raw) => Object.assign(raw.sceneTypes[0]?.setup[0] ?? {}, { title: undefined }),
      /setup\[0\]\.title/,
    ],
    [
      'an oracle selection without a table',
      (raw) => Object.assign(raw.flows[0]?.phases[0]?.selection ?? {}, { table: undefined }),
      /phases\[0\]\.selection\.table/,
    ],
    [
      'an oracle key that is not a string',
      (raw) => raw.flows[0]?.oracles.push(7 as never),
      /flows\[0\]\.oracles\[2\]/,
    ],
  ])('throws on %s, naming where', (_name, break_, message) => {
    const raw = miniRelease()
    break_(raw)

    expect(() => readRelease(_name === 'a release that is not an object' ? [] : raw)).toThrow(
      message,
    )
  })

  it('throws on a check outcome whose effect is malformed, naming the outcome', () => {
    const raw = miniRelease([
      step('roll', 'check', {
        outcomes: [{ band: 'done', effects: [{ kind: 'sheet', field: 'x' }] }],
      }),
    ])

    expect(() => readRelease(raw)).toThrow(/setup\[0\]\.outcomes\[0\]\.effects\[0\]\.op/)
  })

  describe('the phases of a flow', () => {
    const withHooks = () => {
      const raw = miniRelease()
      const phase = raw.flows[0]?.phases[0] as Record<string, unknown>
      // Given out of order and with one empty: they are read in PHASE_HOOKS order, empty ones left out.
      phase.worldTurn = [step('turn', 'prompt', { prompt: 'The world turns.' })]
      phase.sessionOpening = [step('open', 'prompt')]
      phase.sceneClosing = []
      phase.description = 'Draw and play.'
      Object.assign(raw.flows[0] ?? {}, { introduction: 'You carry a saint.' })
      return raw
    }

    it('reads the introduction, and each phase with its selection and its non-empty hooks', () => {
      const flow = readRelease(withHooks()).flows[0]

      expect(flow?.introduction).toBe('You carry a saint.')
      expect(flow?.phases.map((phase) => [phase.key, phase.name, phase.mode])).toEqual([
        ['road', 'Road', 'loop'],
        ['end', 'End', 'once'],
      ])
      expect(flow?.phases[0]).toMatchObject({
        description: 'Draw and play.',
        selection: { rule: 'oracle', table: 'deck' },
      })
      expect(flow?.phases[0]?.hooks.map(({ hook, steps }) => [hook, steps[0]?.key])).toEqual([
        ['sessionOpening', 'open'],
        ['worldTurn', 'turn'],
      ])
      expect(flow?.phases[1]?.selection).toEqual({ rule: 'sequence', sceneTypes: ['scene'] })
      expect(flow?.phases[1]?.hooks).toEqual([])
    })

    it('reads a player selection as a list of Scene Types', () => {
      const raw = miniRelease()
      Object.assign(raw.flows[0]?.phases[1] ?? {}, {
        selection: { rule: 'player', sceneTypes: ['scene'] },
      })

      expect(readRelease(raw).flows[0]?.phases[1]?.selection).toEqual({
        rule: 'player',
        sceneTypes: ['scene'],
      })
    })

    it.each([
      [
        'an unknown selection rule',
        (raw: ReturnType<typeof withHooks>) =>
          Object.assign(raw.flows[0]?.phases[1]?.selection ?? {}, { rule: 'dice' }),
        /flows\[0\]\.phases\[1\]\.selection\.rule: unknown selection rule "dice"/,
      ],
      [
        'an unknown mode',
        (raw: ReturnType<typeof withHooks>) =>
          Object.assign(raw.flows[0]?.phases[0] ?? {}, { mode: 'forever' }),
        /flows\[0\]\.phases\[0\]\.mode: unknown mode "forever"/,
      ],
      [
        'a phase without a selection',
        (raw: ReturnType<typeof withHooks>) =>
          Object.assign(raw.flows[0]?.phases[0] ?? {}, { selection: undefined }),
        /phases\[0\]\.selection: expected an object/,
      ],
      [
        'a sequence without Scene Types',
        (raw: ReturnType<typeof withHooks>) =>
          Object.assign(raw.flows[0]?.phases[1]?.selection ?? {}, { sceneTypes: undefined }),
        /phases\[1\]\.selection\.sceneTypes: expected a list/,
      ],
      [
        'a hook that is not a list',
        (raw: ReturnType<typeof withHooks>) =>
          Object.assign(raw.flows[0]?.phases[0] ?? {}, { worldTurn: 'x' }),
        /phases\[0\]\.worldTurn: expected a list/,
      ],
      [
        'a hook step without a title',
        (raw: ReturnType<typeof withHooks>) =>
          Object.assign(raw.flows[0]?.phases[0] ?? {}, {
            sessionOpening: [{ key: 'a', kind: 'prompt' }],
          }),
        /phases\[0\]\.sessionOpening\[0\]\.title/,
      ],
    ])('throws on %s, naming where', (_name, break_, message) => {
      const raw = withHooks()
      break_(raw)

      expect(() => readRelease(raw)).toThrow(message)
    })
  })
})
