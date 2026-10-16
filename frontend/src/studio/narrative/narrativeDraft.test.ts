import {
  factSlotToDraft,
  factSlotToItem,
  newTracker,
  tagToDraft,
  tagToItem,
  trackerToDraft,
  trackerToItem,
  type TrackerDraft,
} from './narrativeDraft'

const counter = {
  key: 'heat',
  name: 'Heat',
  hint: 'How hot it is',
  kind: 'counter',
  min: 0,
  max: 10,
  initial: 2,
  levels: [{ upTo: 3, label: 'Quiet', colour: 'blue' }, { label: 'Burning' }],
  futureField: { nested: true },
}

function draftOf(definition: Record<string, unknown>): TrackerDraft {
  const draft = trackerToDraft(definition)
  if (draft === null) {
    throw new Error('Expected a readable tracker.')
  }
  return draft
}

describe('a tracker draft', () => {
  it('gives a counter back as it was stored, unknown fields included', () => {
    expect(trackerToItem(draftOf(counter))).toEqual({ item: counter })
  })

  it('gives a clock back as it was stored, unknown fields included', () => {
    const clock = { key: 'alarm', name: 'Alarm', kind: 'clock', segments: 6, extra: [1] }

    expect(trackerToItem(draftOf(clock))).toEqual({ item: clock })
  })

  it('reads a legacy counter with no hint and no levels, and does not invent them', () => {
    const legacy = { key: 'edge', name: 'Edge', kind: 'counter', min: 0, max: 5, initial: 0 }
    const draft = draftOf(legacy)

    expect(draft.hint).toBe('')
    expect(draft.levels).toEqual([])
    expect(trackerToItem(draft)).toEqual({ item: legacy })
  })

  it('omits an emptied hint and an emptied levels list', () => {
    const draft = { ...draftOf(counter), hint: '  ', levels: [] }

    const result = trackerToItem(draft)

    expect(result).toHaveProperty('item')
    expect(result).not.toHaveProperty('item.hint')
    expect(result).not.toHaveProperty('item.levels')
  })

  it('leaves the last level without a bound', () => {
    const draft = draftOf(counter)
    draft.levels = [...draft.levels].reverse()

    expect(trackerToItem(draft)).toMatchObject({
      item: { levels: [{ label: 'Burning' }, { label: 'Quiet', colour: 'blue' }] },
    })
    expect(trackerToItem(draft)).not.toHaveProperty('item.levels.1.upTo')
  })

  it('keeps key, name, hint and unknown fields, and drops the other kind, when the kind changes', () => {
    const draft: TrackerDraft = { ...draftOf(counter), kind: 'clock', segments: '4' }

    expect(trackerToItem(draft)).toEqual({
      item: {
        key: 'heat',
        name: 'Heat',
        hint: 'How hot it is',
        kind: 'clock',
        segments: 4,
        futureField: { nested: true },
      },
    })
  })

  it('keeps the levels a stored clock carries, and the segments a stored counter carries, on an unchanged save', () => {
    const clock = {
      key: 'alarm',
      name: 'Alarm',
      kind: 'clock',
      segments: 6,
      levels: [{ label: 'Calm' }],
    }
    const withSegments = { ...counter, segments: 4 }

    expect(trackerToItem(draftOf(clock))).toEqual({ item: clock })
    expect(trackerToItem(draftOf(withSegments))).toEqual({ item: withSegments })
  })

  it('drops them once the kind changes, and brings them back if it changes back', () => {
    const clock = {
      key: 'alarm',
      name: 'Alarm',
      kind: 'clock',
      segments: 6,
      levels: [{ label: 'Calm' }],
    }
    const draft = draftOf(clock)

    expect(trackerToItem({ ...draft, kind: 'counter' })).not.toHaveProperty('item.segments')
    expect(trackerToItem({ ...draft, kind: 'counter' })).not.toHaveProperty('item.levels')
    expect(trackerToItem({ ...draft, kind: 'clock' })).toEqual({ item: clock })
  })

  it('says which numbers are not whole numbers and sends nothing', () => {
    const draft: TrackerDraft = {
      ...draftOf(counter),
      min: '1.5',
      initial: '',
      levels: [
        { upTo: '2x', label: 'Quiet', extra: {} },
        { upTo: '', label: 'Burning', extra: {} },
      ],
    }

    expect(trackerToItem(draft)).toEqual({
      errors: {
        min: 'Use a whole number.',
        initial: 'Use a whole number.',
        'level-0': 'Use a whole number.',
      },
    })
    expect(trackerToItem({ ...draftOf({ ...counter, kind: 'clock' }), segments: 'abc' })).toEqual({
      errors: { segments: 'Use a whole number.' },
    })
  })

  it('starts as an empty counter', () => {
    expect(newTracker()).toMatchObject({ kind: 'counter', key: '', name: '', levels: [] })
  })

  it.each([
    ['an unknown kind', { ...counter, kind: 'gauge' }],
    ['no kind', { key: 'x', name: 'X' }],
    ['a numeric name', { ...counter, name: 3 }],
    ['levels that are not a list', { ...counter, levels: 'many' }],
    ['a level that is not an object', { ...counter, levels: ['low'] }],
    ['a level bound that is not a number', { ...counter, levels: [{ upTo: 'x', label: 'a' }] }],
    ['a numeric field that is not a number', { ...counter, max: '10' }],
  ])('is not editable with %s', (_name, definition) => {
    expect(trackerToDraft(definition)).toBeNull()
  })
})

describe('a tag draft', () => {
  const tag = { key: 'gang', label: 'Gang', hint: 'Who runs with whom', extra: 1 }

  it('gives a tag back as it was stored', () => {
    expect(tagToItem(tagToDraft(tag) ?? newMissing())).toEqual({ item: tag })
  })

  it('omits an empty hint', () => {
    const draft = tagToDraft({ key: 'gang', label: 'Gang' })

    expect(draft).toMatchObject({ hint: '' })
    expect(tagToItem(draft ?? newMissing())).toEqual({ item: { key: 'gang', label: 'Gang' } })
  })

  it('is not editable with a label that is not text', () => {
    expect(tagToDraft({ key: 'gang', label: ['Gang'] })).toBeNull()
  })
})

describe('a fact slot draft', () => {
  const slot = { key: 'target', label: 'Target', type: 'npc', hint: 'Who', extra: true }

  it('gives a fact slot back as it was stored', () => {
    expect(factSlotToItem(factSlotToDraft(slot) ?? newMissing())).toEqual({ item: slot })
  })

  it('sends the type the form chose', () => {
    const draft = { ...(factSlotToDraft(slot) ?? newMissing()), type: 'thread' }

    expect(factSlotToItem(draft)).toMatchObject({ item: { type: 'thread' } })
  })

  it.each([
    ['an unknown type', { ...slot, type: 'place' }],
    ['no type', { key: 'target', label: 'Target' }],
  ])('is not editable with %s', (_name, definition) => {
    expect(factSlotToDraft(definition)).toBeNull()
  })
})

function newMissing(): never {
  throw new Error('Expected a readable item.')
}
