import { ambush } from '../../fixtures'
import {
  newSceneTypeToItem,
  sceneTypeToDraft,
  sceneTypeToItem,
  type SceneTypeDraft,
} from './sceneTypeDraft'
import { sceneTypeAnswer } from './sceneTypeError'

function draftOf(definition: Record<string, unknown>): SceneTypeDraft {
  const draft = sceneTypeToDraft(definition)
  if (draft === null) {
    throw new Error('Expected a readable Scene Type.')
  }
  return draft
}

describe('a Scene Type draft', () => {
  it('gives a Scene Type back as it was stored, unknown fields included', () => {
    expect(sceneTypeToItem(draftOf(ambush))).toEqual({ item: ambush })
  })

  it('reads a legacy Scene Type with no tips and no step lists, and does not invent tips', () => {
    const legacy = { key: 'old', name: 'Old', purpose: 'Before tips.', oracles: [] }
    const draft = draftOf(legacy)

    expect(draft.tips).toBe('')
    expect(draft.steps).toEqual({ setup: [], play: [], closing: [] })
    expect(sceneTypeToItem(draft)).toEqual({
      item: { ...legacy, setup: [], play: [], closing: [] },
    })
  })

  it('omits emptied tips and trims the text fields', () => {
    const result = sceneTypeToItem({
      ...draftOf(ambush),
      key: ' chase ',
      name: ' Chase ',
      purpose: ' Run. ',
      tips: '  ',
    })

    expect(result).toMatchObject({ item: { key: 'chase', name: 'Chase', purpose: 'Run.' } })
    expect(result).not.toHaveProperty('item.tips')
  })

  it('keeps an oracle shortcut the draft no longer has until it is unticked', () => {
    const draft = draftOf({ ...ambush, oracles: ['gone'] })

    expect(draft.oracles).toEqual(['gone'])
    expect(sceneTypeToItem(draft)).toMatchObject({ item: { oracles: ['gone'] } })
  })

  it.each([
    ['a purpose that is not text', { purpose: 42 }],
    ['tips that are not text', { tips: ['a'] }],
    ['oracles that are not a list of keys', { oracles: 'fate' }],
    ['oracles with a key that is not text', { oracles: [1] }],
    ['a step list that is not a list', { play: {} }],
  ])('cannot read %s', (_, change) => {
    expect(sceneTypeToDraft({ ...ambush, ...change })).toBeNull()
  })

  it('keeps a step it cannot read as it was stored', () => {
    const odd = { ...ambush, play: [{ key: 'x', kind: 'mystery' }] }

    expect(sceneTypeToItem(draftOf(odd))).toEqual({ item: odd })
  })

  it('refuses to write while any step list holds a value that is not a whole number', () => {
    const draft = draftOf({
      ...ambush,
      closing: [
        { key: 'roll', kind: 'roll', title: 'Roll', dice: '1d6', bands: [{ upTo: 3 }, {}] },
      ],
    })
    const [roll] = draft.steps.closing
    if (roll?.kind !== 'roll' || roll.bands[0] === undefined) {
      throw new Error('Expected a roll step.')
    }
    roll.bands[0] = { ...roll.bands[0], upTo: { form: 'integer', text: 'abc' } }

    const result = sceneTypeToItem(draft)

    expect(result).toHaveProperty('errors.closing')
    expect(result).toHaveProperty('errors.setup', {})
    expect(result).not.toHaveProperty('item')
  })

  it('creates the smallest Scene Type the publisher accepts', () => {
    expect(newSceneTypeToItem({ key: ' heist ', name: ' Heist ', purpose: ' Steal it. ' })).toEqual(
      {
        item: {
          key: 'heist',
          name: 'Heist',
          purpose: 'Steal it.',
          oracles: [],
          setup: [],
          play: [],
          closing: [],
        },
      },
    )
  })
})

describe('the answer to a refused save', () => {
  it('sends a step message to the list and card it names', () => {
    const answer = sceneTypeAnswer('sceneTypes[1].play[2].title: must not be blank', 1)

    expect(answer.lists.play).toEqual([
      expect.objectContaining({ index: 2, field: 'title', message: 'must not be blank' }),
    ])
    expect(answer.lists.setup).toEqual([])
    expect(answer.page).toBeNull()
  })

  it('sends a field message to its field without the path', () => {
    expect(sceneTypeAnswer('sceneTypes[0].name: must not be blank', 0).fields).toEqual({
      name: 'must not be blank',
    })
    expect(sceneTypeAnswer('sceneTypes[0].oracles[1]: unknown oracle', 0).fields).toEqual({
      oracles: 'unknown oracle',
    })
  })

  it('keeps any other message whole on the page, never lost', () => {
    expect(sceneTypeAnswer('sceneTypes[3].name: must not be blank', 0).page).toBe(
      'sceneTypes[3].name: must not be blank',
    )
    expect(sceneTypeAnswer('flows[0].phases: needs one', 0).page).toBe('flows[0].phases: needs one')
    expect(sceneTypeAnswer('sceneTypes[0].colour: unknown', 0).page).toBe(
      'sceneTypes[0].colour: unknown',
    )
  })
})
