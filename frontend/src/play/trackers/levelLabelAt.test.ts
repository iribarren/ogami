import { describe, expect, it } from 'vitest'

import { levelLabelAt } from './useTrackers'

const bounded = [
  {
    key: 'rank',
    levels: [
      { upTo: 1, label: 'Easy' },
      { upTo: 3, label: 'Hard' },
    ],
  },
]
const unbounded = [
  {
    key: 'rank',
    levels: [
      { upTo: 1, label: 'Easy' },
      { upTo: null, label: 'Deadly' },
    ],
  },
]

describe('levelLabelAt', () => {
  it('finds the first level the value does not pass', () => {
    expect([0, 1, 2, 3].map((value) => levelLabelAt(bounded, 'rank', value))).toEqual([
      'Easy',
      'Easy',
      'Hard',
      'Hard',
    ])
  })

  it('has no label past every bounded level, as the backend has none', () => {
    expect(levelLabelAt(bounded, 'rank', 4)).toBeNull()
  })

  it('labels a value past the bounded levels with the unbounded one', () => {
    expect(levelLabelAt(unbounded, 'rank', 9)).toBe('Deadly')
  })

  it('has no label for an unknown Tracker or one without levels', () => {
    expect([
      levelLabelAt(bounded, 'other', 1),
      levelLabelAt([{ key: 'rank', levels: [] }], 'rank', 1),
    ]).toEqual([null, null])
  })
})
