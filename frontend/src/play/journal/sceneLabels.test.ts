import { playOrdinals, titleAddsToType, typeLabel } from './sceneLabels'

describe('titleAddsToType', () => {
  it.each([
    ['The scene', 'The scene'],
    ['the SCENE', 'The scene'],
    ['The scene 3', 'The scene'],
    ['Archive 12', 'archive'],
  ])('is false for the title %s of the Scene Type %s, which only repeats it', (title, type) => {
    expect(titleAddsToType(title, type)).toBe(false)
  })

  it.each([
    ['Went Go right', 'Tour'],
    ['Tour guide', 'Tour'],
    ['Tour 3 again', 'Tour'],
    ['Tour 3.5', 'Tour'],
    ['Tour', ''],
  ])('is true for the title %s of the Scene Type %s', (title, type) => {
    expect(titleAddsToType(title, type)).toBe(true)
  })

  it('is true without a Scene Type to repeat', () => {
    expect(titleAddsToType('Tour 1', null)).toBe(true)
  })
})

describe('typeLabel', () => {
  it.each([null, '', '  ', 'Scene', 'scene'])('is null for %j, which adds nothing', (name) => {
    expect(typeLabel(name)).toBeNull()
  })

  it('is the trimmed Scene Type name otherwise', () => {
    expect(typeLabel(' Tour ')).toBe('Tour')
  })
})

describe('playOrdinals', () => {
  it('numbers only scenes of play, in order and without gaps', () => {
    const scenes = [
      { number: 1, kind: 'hook' },
      { number: 2, kind: 'scene' },
      { number: 3, kind: 'scene' },
      { number: 4, kind: 'hook' },
      { number: 5, kind: 'hook' },
      { number: 6, kind: 'scene' },
    ] as const

    expect([...playOrdinals(scenes)]).toEqual([
      [2, 1],
      [3, 2],
      [6, 3],
    ])
  })

  it('orders by the stored number, whatever the order given', () => {
    const scenes = [
      { number: 4, kind: 'scene' },
      { number: 2, kind: 'scene' },
    ] as const

    expect(playOrdinals(scenes).get(2)).toBe(1)
    expect(playOrdinals(scenes).get(4)).toBe(2)
  })

  it('is empty without scenes', () => {
    expect(playOrdinals([]).size).toBe(0)
  })
})
