import { alarmTracker, edgeTracker, heatTracker } from '../campaigns/fixtures'
import { MAX_PIPS, trackerPips } from './useTrackers'

describe('trackerPips', () => {
  it('has one pip per point above the minimum, filled up to the value', () => {
    expect(trackerPips(alarmTracker)).toEqual({ total: 6, filled: 2 })
    expect(trackerPips(edgeTracker)).toEqual({ total: 3, filled: 1 })
    // A range that dips below zero counts from its minimum: -5..5 at 0 fills 5 of 10.
    expect(trackerPips(heatTracker)).toEqual({ total: 10, filled: 5 })
  })

  it('counts a range that starts above zero from zero, so the filled pips match the value', () => {
    // A 1..9 chaos factor at 5 fills 5 of 9, not 4 of 8.
    expect(trackerPips({ ...edgeTracker, min: 1, max: 9, value: 5 })).toEqual({
      total: 9,
      filled: 5,
    })
  })

  it('counts from zero before the cutoff, so a high range draws no pips', () => {
    // 10..14 counts as 14 points from zero: over the cutoff of 12, though only 4 above its minimum.
    expect(trackerPips({ ...edgeTracker, min: 10, max: 14, value: 12 })).toBeNull()
  })

  it('keeps the filled count inside the range', () => {
    expect(trackerPips({ ...edgeTracker, value: 9 })).toEqual({ total: 3, filled: 3 })
    expect(trackerPips({ ...edgeTracker, value: -4 })).toEqual({ total: 3, filled: 0 })
  })

  it('draws up to the cutoff and no pips beyond it', () => {
    expect(trackerPips({ ...edgeTracker, min: 0, max: MAX_PIPS })?.total).toBe(MAX_PIPS)
    expect(MAX_PIPS).toBe(12)
    expect(trackerPips({ ...edgeTracker, min: 0, max: MAX_PIPS + 1 })).toBeNull()
  })

  it('draws no pips for a range without points', () => {
    expect(trackerPips({ ...edgeTracker, min: 2, max: 2, value: 2 })).toBeNull()
  })
})
