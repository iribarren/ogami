import { titleAddsToType } from './sceneLabels'

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
