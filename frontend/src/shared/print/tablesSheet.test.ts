import { readdirSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { readRelease } from './release'
import { buildTablesSheet } from './tablesSheet'
import { miniRelease } from './testRelease'

const presets = resolve(import.meta.dirname, '../../../../backend/presets')

type Raw = ReturnType<typeof miniRelease>

function release(change: (raw: Raw) => void = () => undefined) {
  const raw = miniRelease()
  change(raw)
  return readRelease(raw)
}

const luck = (raw: Raw) => raw.oracles.tables[1] ?? { entries: [] }

describe('buildTablesSheet', () => {
  it('lists the tables of the flow in flow order, leaving out its likelihood oracles', () => {
    const tables = buildTablesSheet(
      release((raw) => {
        raw.flows[0]?.oracles.push('deck')
      }),
      'mini',
    )

    expect(tables.map((table) => table.key)).toEqual(['luck', 'deck'])
  })

  it('numbers a weighted table on the smallest die, rolling again above its total', () => {
    const [deck] = buildTablesSheet(
      release((raw) => {
        raw.flows[0]?.oracles.splice(0, 2, 'deck')
      }),
      'mini',
    )

    // Weights 3 and 1 total 4: a d4 with nothing to roll again.
    expect(deck).toMatchObject({ name: 'The deck', die: 4, rollAgainAbove: null })
    expect(deck?.rows.map((row) => [row.range, row.text])).toEqual([
      ['1-3', 'One'],
      ['4', 'Two'],
    ])
  })

  it('rolls again above a total that is not a die size', () => {
    const [table] = buildTablesSheet(
      release((raw) => {
        Object.assign(luck(raw).entries[0] ?? {}, { weight: 4 })
      }),
      'mini',
    )

    expect(table).toMatchObject({ die: 6, rollAgainAbove: 5 })
    expect(table?.rows.map((row) => row.range)).toEqual(['1-4', '5'])
  })

  it('prints the numbers of a ranged table as they are, a single number when both ends are equal', () => {
    const [table] = buildTablesSheet(
      release((raw) => {
        Object.assign(luck(raw).entries[0] ?? {}, { min: 1, max: 5 })
        Object.assign(luck(raw).entries[1] ?? {}, { min: 6, max: 6 })
      }),
      'mini',
    )

    expect(table?.rows.map((row) => row.range)).toEqual(['1-5', '6'])
    expect(table).toMatchObject({ die: 6, rollAgainAbove: null })
  })

  it('words the effects of an entry, and leaves out the ones with no wording', () => {
    const [table] = buildTablesSheet(
      release((raw) => {
        Object.assign(luck(raw).entries[0] ?? {}, {
          effects: [
            { kind: 'nextScene', sceneType: 'scene' },
            { kind: 'createThread', name: 'Find the lamp' },
            { kind: 'tracker', tracker: 'doom', op: 'add', value: -2 },
            { kind: 'tracker', tracker: 'nerve', op: 'set', value: 3 },
            { kind: 'rollTable', table: 'deck' },
          ],
        })
      }),
      'mini',
    )

    expect(table?.rows[0]?.effects).toEqual([
      'Next scene: Scene scene',
      'Start a Thread: Find the lamp',
      'Doom -2',
      'Set Nerve to 3',
    ])
    expect(table?.rows[1]?.effects).toEqual([])
  })

  it('prints no sheet for a flow without tables', () => {
    expect(
      buildTablesSheet(
        release((raw) => {
          raw.flows[0]?.oracles.splice(0, 2, 'yes-no')
        }),
        'mini',
      ),
    ).toEqual([])
  })

  it.each([
    ['a flow the release does not have', 'nope', () => undefined, /The release has no flow "nope"/],
    [
      'an oracle that is neither a table nor a likelihood oracle',
      'mini',
      (raw: Raw) => raw.flows[0]?.oracles.push('gone'),
      /The flow's oracle "gone" is neither a table/,
    ],
    [
      'a table over a d100',
      'mini',
      (raw: Raw) => Object.assign(luck(raw).entries[0] ?? {}, { weight: 100 }),
      /The table "luck" cannot be printed: .*more than a d100/,
    ],
    [
      'a table with no entries',
      'mini',
      (raw: Raw) => (luck(raw).entries.length = 0),
      /The table "luck" cannot be printed: .*no entries/,
    ],
    [
      'a table that mixes ranged and unranged entries',
      'mini',
      (raw: Raw) => Object.assign(luck(raw).entries[0] ?? {}, { min: 1, max: 2 }),
      /The table "luck" cannot be printed: it mixes entries with ranges/,
    ],
    [
      'an entry that moves a Tracker the release lacks',
      'mini',
      (raw: Raw) =>
        Object.assign(luck(raw).entries[0] ?? {}, {
          effects: [{ kind: 'tracker', tracker: 'gone', op: 'add', value: 1 }],
        }),
      /The table "luck" cannot be printed: luck: a add effect on "gone"/,
    ],
    [
      'an entry that names a Scene Type the release lacks',
      'mini',
      (raw: Raw) =>
        Object.assign(luck(raw).entries[0] ?? {}, {
          effects: [{ kind: 'nextScene', sceneType: 'gone' }],
        }),
      /The table "luck" cannot be printed: luck names the Scene Type "gone"/,
    ],
  ])('says what is wrong with %s, and does not build it', (_name, flow, change, message) => {
    expect(() => buildTablesSheet(release(change), flow)).toThrow(message)
  })

  it('builds the tables of every flow of every preset', () => {
    const files = readdirSync(presets).filter((name) => name.endsWith('.json'))

    expect(files.length).toBeGreaterThan(0)
    for (const file of files) {
      const preset = readRelease(JSON.parse(readFileSync(resolve(presets, file), 'utf8')))
      for (const flow of preset.flows) {
        const tables = buildTablesSheet(preset, flow.key)

        expect(tables.length, `${file} ${flow.key}`).toBe(
          flow.oracles.filter((key) => !preset.likelihoods.includes(key)).length,
        )
        for (const table of tables) {
          expect(table.rows.length).toBeGreaterThan(0)
        }
      }
    }
  })
})
