import { sceneTypeAnswer } from './sceneTypeError'

describe('reading a refusal for a Scene Type', () => {
  it('gives a step of a list to that list', () => {
    const answer = sceneTypeAnswer('sceneTypes[2].play[1].title: must not be blank', 2)

    expect(answer.lists.play).toMatchObject([{ index: 1, field: 'title' }])
    expect(answer.page).toBeNull()
  })

  it('gives a message about the list itself to the list, as a list-level error', () => {
    const answer = sceneTypeAnswer('sceneTypes[2].setup: must be a list', 2)

    expect(answer.lists.setup).toEqual([
      {
        index: null,
        band: null,
        effect: null,
        field: null,
        message: 'sceneTypes[2].setup: must be a list',
      },
    ])
    expect(answer.page).toBeNull()
  })

  it('does not take a path that only starts like a list for that list', () => {
    const text = 'sceneTypes[2].setupX[0].title: must not be blank'
    const answer = sceneTypeAnswer(text, 2)

    expect(answer.lists.setup).toEqual([])
    expect(answer.page).toBe(text)
  })

  it('gives a field to the field and another Scene Type to the page', () => {
    expect(sceneTypeAnswer('sceneTypes[2].oracles[0]: unknown oracle', 2).fields).toEqual({
      oracles: 'unknown oracle',
    })
    const other = 'sceneTypes[1].play[0].title: must not be blank'
    expect(sceneTypeAnswer(other, 2).page).toBe(other)
  })
})
