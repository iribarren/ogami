import { readdirSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { readRelease } from './release'
import { miniRelease } from './testRelease'
import { buildTrackerSheet, WRITE_IN_ABOVE } from './trackerSheet'

const presets = resolve(import.meta.dirname, '../../../../backend/presets')

type Raw = ReturnType<typeof miniRelease>

function release(change: (raw: Raw) => void = () => undefined) {
  const raw = miniRelease()
  change(raw)
  return readRelease(raw)
}

const nerve = (raw: Raw) => raw.trackers[1] ?? {}

describe('buildTrackerSheet', () => {
  it('prints the trackers of the flow in flow order, a clock as its segments', () => {
    const trackers = buildTrackerSheet(
      release((raw) => raw.flows[0]?.trackers.push('nerve')),
      'mini',
    )

    expect(trackers.map((tracker) => tracker.key)).toEqual(['doom', 'nerve'])
    expect(trackers[0]).toEqual({
      kind: 'clock',
      key: 'doom',
      name: 'Doom',
      hint: 'Near.',
      segments: 6,
    })
  })

  it('leaves out a tracker the flow does not use', () => {
    expect(buildTrackerSheet(release(), 'mini').map((tracker) => tracker.key)).toEqual(['doom'])
  })

  it('draws a counter as boxes from its minimum to its maximum, with its levels and its start', () => {
    const [counter] = buildTrackerSheet(
      release((raw) => raw.flows[0]?.trackers.splice(0, 1, 'nerve')),
      'mini',
    )

    expect(counter).toMatchObject({
      kind: 'counter',
      name: 'Nerve',
      hint: 'Held.',
      min: 0,
      max: 5,
      range: '0-5',
      start: 2,
    })
    expect(counter?.kind === 'counter' && counter.boxes).toEqual([
      { value: 0, label: 'Shaken' },
      { value: 1 },
      { value: 2, label: 'Steady' },
      { value: 3 },
      { value: 4 },
      { value: 5 },
    ])
    expect(counter?.kind === 'counter' && counter.levels).toEqual([
      { label: 'Shaken', range: '0-1' },
      { label: 'Steady', range: '2-5' },
    ])
  })

  it('counts from a minimum above 0, and has no start when the release names none', () => {
    const [counter] = buildTrackerSheet(
      release((raw) => {
        Object.assign(nerve(raw), { min: 1, max: 3, initial: undefined, levels: undefined })
        raw.flows[0]?.trackers.splice(0, 1, 'nerve')
      }),
      'mini',
    )

    expect(counter).toMatchObject({ range: '1-3', start: undefined, levels: [] })
    expect(counter?.kind === 'counter' && counter.boxes?.map((box) => box.value)).toEqual([1, 2, 3])
  })

  it('gives a counter of more than the box limit a write-in box with its range, and keeps its levels', () => {
    const [counter] = buildTrackerSheet(
      release((raw) => {
        Object.assign(nerve(raw), { max: WRITE_IN_ABOVE, initial: 0 })
        raw.flows[0]?.trackers.splice(0, 1, 'nerve')
      }),
      'mini',
    )

    expect(counter).toMatchObject({ boxes: null, range: `0-${String(WRITE_IN_ABOVE)}`, start: 0 })
    expect(counter?.kind === 'counter' && counter.levels).toEqual([
      { label: 'Shaken', range: '0-1' },
      { label: 'Steady', range: `2-${String(WRITE_IN_ABOVE)}` },
    ])
  })

  it('draws a counter of exactly the box limit as boxes', () => {
    const [counter] = buildTrackerSheet(
      release((raw) => {
        Object.assign(nerve(raw), { max: WRITE_IN_ABOVE - 1, levels: undefined })
        raw.flows[0]?.trackers.splice(0, 1, 'nerve')
      }),
      'mini',
    )

    expect(counter?.kind === 'counter' && counter.boxes).toHaveLength(WRITE_IN_ABOVE)
  })

  it('has no sheet for a flow without trackers', () => {
    const bare = release((raw) => raw.flows[0]?.trackers.splice(0))

    expect(buildTrackerSheet(bare, 'mini')).toEqual([])
  })

  it.each([
    ['a flow the release does not have', 'nope', () => undefined, /The release has no flow "nope"/],
    [
      'a tracker the release does not have',
      'mini',
      (raw: Raw) => raw.flows[0]?.trackers.push('gone'),
      /The flow "mini" uses the Tracker "gone", which the release does not have/,
    ],
    [
      'a clock without a whole number of segments',
      'mini',
      (raw: Raw) => Object.assign(raw.trackers[0] ?? {}, { segments: 2.5 }),
      /The Tracker "doom" cannot be printed: .*segments/,
    ],
    [
      'a counter that ends below its start',
      'mini',
      (raw: Raw) => Object.assign(nerve(raw), { min: 4, max: 2, initial: undefined }),
      /The Tracker "nerve" cannot be printed: .*from 4 to 2/,
    ],
    [
      'a counter with fractional bounds',
      'mini',
      (raw: Raw) => Object.assign(nerve(raw), { max: 4.5 }),
      /The Tracker "nerve" cannot be printed: .*whole numbers/,
    ],
    [
      'a level with a fractional threshold',
      'mini',
      (raw: Raw) => Object.assign(nerve(raw), { levels: [{ upTo: 2.5, label: 'A' }] }),
      /The Tracker "nerve" cannot be printed: .*level "A".*whole number/,
    ],
    [
      'a starting value outside the counter',
      'mini',
      (raw: Raw) => Object.assign(nerve(raw), { initial: 9 }),
      /The Tracker "nerve" cannot be printed: .*starts at 9.*0 to 5/,
    ],
    [
      'levels that do not ascend',
      'mini',
      (raw: Raw) =>
        Object.assign(nerve(raw), {
          levels: [
            { upTo: 3, label: 'A' },
            { upTo: 1, label: 'B' },
          ],
        }),
      /The Tracker "nerve" cannot be printed: .*level "B"/,
    ],
    [
      'a level above the maximum',
      'mini',
      (raw: Raw) => Object.assign(nerve(raw), { levels: [{ upTo: 9, label: 'A' }] }),
      /The Tracker "nerve" cannot be printed: .*level "A".*above 5/,
    ],
    [
      'a level without a threshold that is not the last',
      'mini',
      (raw: Raw) =>
        Object.assign(nerve(raw), { levels: [{ label: 'A' }, { upTo: 4, label: 'B' }] }),
      /The Tracker "nerve" cannot be printed: .*level "A" has no "upTo"/,
    ],
  ])('says what is wrong with %s, and does not build it', (_name, flow, change, message) => {
    const used = (raw: Raw) => {
      change(raw)
      if (raw.flows[0]?.trackers.length === 1) {
        raw.flows[0].trackers.push('nerve')
      }
    }

    expect(() => buildTrackerSheet(release(used), flow)).toThrow(message)
  })

  it('builds the trackers of every flow of every preset', () => {
    const files = readdirSync(presets).filter((name) => name.endsWith('.json'))

    expect(files.length).toBeGreaterThan(0)
    for (const file of files) {
      const preset = readRelease(JSON.parse(readFileSync(resolve(presets, file), 'utf8')))
      for (const flow of preset.flows) {
        const trackers = buildTrackerSheet(preset, flow.key)

        expect(
          trackers.map((tracker) => tracker.key),
          `${file} ${flow.key}`,
        ).toEqual(flow.trackers)
      }
    }
  })
})
