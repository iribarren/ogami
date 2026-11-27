import { readdirSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { readRelease } from './release'
import { miniRelease, step } from './testRelease'

const presets = resolve(process.cwd(), '../backend/presets')

describe('readRelease', () => {
  it('reads what printing needs from a release', () => {
    const release = readRelease(miniRelease())

    expect(release.systemName).toBe('Mini')
    expect(release.license).toBe('CC BY-SA 4.0')
    expect(release.trackers.map((tracker) => [tracker.key, tracker.kind, tracker.size])).toEqual([
      ['doom', 'clock', 6],
      ['nerve', 'counter', 5],
    ])
    expect(release.factSlots).toEqual([{ key: 'sin', label: 'The Sin' }])
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
      /tables\[0\]\.entries\[0\]\.weight/,
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
})
