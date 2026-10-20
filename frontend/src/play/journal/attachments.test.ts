import { describe, expect, it } from 'vitest'

import { attachableIn, attachedTo, attachmentLabel, isAttachable, isAttached } from './attachments'
import {
  chosenWay,
  fateDouble,
  fateDoubleEvent,
  gateNote,
  keepThreeRoll,
  lockedDoorAnswer,
  noteAttaching,
  pickedTwo,
  plainAnswer,
  skillCheckEntry,
  stormyWeather,
} from './fixtures'

describe('isAttachable', () => {
  it.each([
    ['a roll', keepThreeRoll, true],
    ['an oracle table result', stormyWeather, true],
    ['a likelihood answer', lockedDoorAnswer, true],
    ['a check', skillCheckEntry, true],
    ['a random event result', fateDoubleEvent, false],
    ['a note', gateNote, false],
    ['a choice', chosenWay, false],
    ['a pick', pickedTwo, false],
  ])('says %s: %s', (_name, entry, attachable) => {
    expect(isAttachable(entry)).toBe(attachable)
  })
})

describe('attachableIn', () => {
  const entries = [gateNote, keepThreeRoll, stormyWeather, fateDouble, fateDoubleEvent]

  it("keeps the current scene's attachable results in journal order", () => {
    expect(attachableIn(entries, { session: 2, scene: 1 })).toEqual([stormyWeather, fateDouble])
    expect(attachableIn(entries, { session: 1, scene: 1 })).toEqual([keepThreeRoll])
  })

  it('offers nothing in a scene without results or outside a scene', () => {
    expect(attachableIn(entries, { session: 2, scene: 2 })).toEqual([])
    expect(attachableIn(entries, null)).toEqual([])
  })
})

describe('isAttached and attachedTo', () => {
  const note = noteAttaching([stormyWeather.id, 'gone', lockedDoorAnswer.id])
  const entries = [lockedDoorAnswer, stormyWeather, plainAnswer, note]

  it('tells the entries a note of the list attaches', () => {
    expect(isAttached(entries, stormyWeather)).toBe(true)
    expect(isAttached(entries, lockedDoorAnswer)).toBe(true)
    expect(isAttached(entries, plainAnswer)).toBe(false)
  })

  it("resolves a note's attachments in the note's order, leaving out unknown ids", () => {
    expect(attachedTo(entries, note)).toEqual([stormyWeather, lockedDoorAnswer])
  })

  it('attaches nothing to a note without attachments or to another kind of entry', () => {
    expect(attachedTo(entries, gateNote)).toEqual([])
    expect(attachedTo(entries, stormyWeather)).toEqual([])
    expect(isAttached([gateNote, stormyWeather], stormyWeather)).toBe(false)
  })
})

describe('attachmentLabel', () => {
  it.each([
    [keepThreeRoll, 'Roll 4d6kh3+2 = 17'],
    [stormyWeather, 'Weather: Hail'],
    [lockedDoorAnswer, 'Is the door locked? → Exceptional yes'],
    [plainAnswer, 'Yes/no question → No'],
  ])('names a result in a short line', (entry, label) => {
    expect(attachmentLabel(entry)).toBe(label)
  })

  it('names a check by who rolled it, its outcome and its total', () => {
    expect(attachmentLabel(skillCheckEntry)).toMatch(/ · .+: .+ \(\d+\)$/)
  })
})
