import { readdirSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { buildChecksReference } from './checksReference'
import { readRelease } from './release'
import { miniRelease } from './testRelease'

const presets = resolve(import.meta.dirname, '../../../../backend/presets')

type Raw = ReturnType<typeof miniRelease>

function release(change: (raw: Raw) => void = () => undefined) {
  const raw = miniRelease()
  change(raw)
  return readRelease(raw)
}

describe('buildChecksReference', () => {
  it('lists the checks in release order with label, hint, dice and the band labels in order', () => {
    const { checks } = buildChecksReference(
      release((raw) => {
        raw.checks.push({
          key: 'second',
          label: 'Second roll',
          hint: 'Count the sixes.',
          dice: undefined,
          bands: [
            { key: 'miss', label: 'Miss', upTo: 0 },
            { key: 'hit', label: 'Hit' },
          ],
        } as never)
      }),
      'mini',
    )

    expect(checks).toEqual([
      {
        key: 'risk',
        label: 'Risk roll',
        hint: 'Roll a d6: high is good.',
        dice: '1d6',
        bands: ['Goes wrong', 'Done'],
      },
      { key: 'second', label: 'Second roll', hint: 'Count the sixes.', bands: ['Miss', 'Hit'] },
    ])
  })

  it('words each level of a likelihood oracle by the roll that answers yes, and its exceptional percent', () => {
    const { oracles } = buildChecksReference(release(), 'mini')

    expect(oracles).toEqual([
      {
        key: 'yes-no',
        name: 'Yes/no question',
        die: 100,
        levels: [
          { label: 'Unlikely', answer: 'Yes on 30 or less' },
          { label: 'Likely', answer: 'Yes on 70 or less' },
        ],
        exceptional:
          'An exceptional answer on the most extreme 10% of the yes rolls and of the no rolls.',
      },
    ])
  })

  it('prints no exceptional sentence for an exceptional percent of 0', () => {
    const { oracles } = buildChecksReference(
      release((raw) => Object.assign(raw.oracles.likelihood[0] ?? {}, { exceptionalPercent: 0 })),
      'mini',
    )

    expect(oracles[0]?.exceptional).toBeUndefined()
  })

  it('words a level that is never or always yes', () => {
    const { oracles } = buildChecksReference(
      release((raw) => {
        const levels = raw.oracles.likelihood[0]?.levels ?? []
        levels[0] = { key: 'never', label: 'Impossible', target: 0 }
        levels[1] = { key: 'sure', label: 'Certain', target: 100 }
        Object.assign(raw.oracles.likelihood[0] ?? {}, { exceptionalPercent: undefined })
      }),
      'mini',
    )

    expect(oracles[0]?.levels.map((level) => level.answer)).toEqual(['Always no', 'Always yes'])
    expect(oracles[0]?.exceptional).toBeUndefined()
  })

  it('takes only the likelihood oracles of the flow, in flow order', () => {
    const { oracles } = buildChecksReference(
      release((raw) => {
        raw.oracles.likelihood.unshift({
          ...(raw.oracles.likelihood[0] ?? {}),
          key: 'other',
          name: 'Other',
        } as never)
        raw.oracles.likelihood.push({
          ...(raw.oracles.likelihood[1] ?? {}),
          key: 'third',
          name: 'Third',
        } as never)
        raw.flows[0]?.oracles.splice(0, 2, 'third', 'yes-no')
      }),
      'mini',
    )

    expect(oracles.map((oracle) => oracle.key)).toEqual(['third', 'yes-no'])
  })

  it('does not report a flow oracle that is a table, nor one that is neither: the tables sheet does', () => {
    const { oracles } = buildChecksReference(
      release((raw) => raw.flows[0]?.oracles.push('gone')),
      'mini',
    )

    expect(oracles.map((oracle) => oracle.key)).toEqual(['yes-no'])
  })

  it('has nothing to print without checks and likelihood oracles', () => {
    const reference = buildChecksReference(
      release((raw) => {
        raw.checks.length = 0
        raw.flows[0]?.oracles.splice(0, 1)
      }),
      'mini',
    )

    expect(reference).toEqual({ checks: [], oracles: [] })
  })

  it('says a flow the release does not have', () => {
    expect(() => buildChecksReference(release(), 'nope')).toThrow(/The release has no flow "nope"/)
  })

  it('builds the reference of every flow of every preset', () => {
    const files = readdirSync(presets).filter((name) => name.endsWith('.json'))

    expect(files.length).toBeGreaterThan(0)
    for (const file of files) {
      const preset = readRelease(JSON.parse(readFileSync(resolve(presets, file), 'utf8')))
      for (const flow of preset.flows) {
        const reference = buildChecksReference(preset, flow.key)

        expect(reference.checks.length, `${file} ${flow.key}`).toBe(preset.checks.length)
        expect(reference.oracles.length, `${file} ${flow.key}`).toBe(
          flow.oracles.filter((key) => preset.likelihoods.includes(key)).length,
        )
      }
    }
  })
})
