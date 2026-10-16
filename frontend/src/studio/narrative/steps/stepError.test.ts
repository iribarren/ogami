import { mapStepError } from './stepError'

const base = 'sceneTypes[2].play'

describe('mapping a server error to a step', () => {
  it('finds the card and the field', () => {
    expect(mapStepError(base, 'sceneTypes[2].play[1].title: must not be blank')).toEqual({
      index: 1,
      band: null,
      field: 'title',
      message: 'must not be blank',
    })
  })

  it('finds the band and its field', () => {
    expect(mapStepError(base, 'sceneTypes[2].play[1].bands[0].upTo: must increase')).toEqual({
      index: 1,
      band: 0,
      field: 'upTo',
      message: 'must increase',
    })
  })

  it('names the field of an indexed value, and the step itself when the path stops there', () => {
    expect(mapStepError(base, 'sceneTypes[2].play[0].inspiration[1]: unknown table')).toMatchObject(
      {
        index: 0,
        field: 'inspiration',
      },
    )
    expect(mapStepError(base, 'sceneTypes[2].play[3]: unknown kind "x"')).toEqual({
      index: 3,
      band: null,
      field: null,
      message: 'unknown kind "x"',
    })
    expect(mapStepError(base, 'sceneTypes[2].play[2].bands[1]: needs a limit')).toMatchObject({
      index: 2,
      band: 1,
      field: null,
    })
  })

  it('keeps a nested field as the first one and the message whole', () => {
    expect(mapStepError(base, 'sceneTypes[2].play[0].count.of: bad: really')).toEqual({
      index: 0,
      band: null,
      field: 'count',
      message: 'bad: really',
    })
  })

  it('shows anything else as a list-level error, text intact', () => {
    const others = [
      'sceneTypes[2].play: at most 50 steps',
      'sceneTypes[3].play[0].title: must not be blank',
      'sceneTypes[2].setup[0].title: must not be blank',
      'The draft changed.',
    ]
    for (const text of others) {
      expect(mapStepError(base, text)).toEqual({
        index: null,
        band: null,
        field: null,
        message: text,
      })
    }
  })
})
