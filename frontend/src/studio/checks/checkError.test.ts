import { checkAnswer } from './checkError'

describe('checkAnswer', () => {
  it.each([
    ['key', 'key'],
    ['label', 'label'],
    ['hint', 'hint'],
    ['dice', 'dice'],
    ['modifier', 'modifier'],
    ['difficulty', 'difficulty'],
    ['difficulty.label', 'difficultyLabel'],
    ['difficulty.default', 'difficultyDefault'],
    ['difficulty.min', 'difficultyMin'],
    ['difficulty.max', 'difficultyMax'],
  ])('puts checks[2].%s on the %s input', (path, input) => {
    expect(checkAnswer(`checks[2].${path}: is wrong`, 2)).toEqual({
      fields: { [input]: 'is wrong' },
      form: null,
    })
  })

  it('keeps the message whole, colons and line breaks included', () => {
    expect(checkAnswer('checks[0].dice: unknown token: "x"\nsecond line', 0)).toEqual({
      fields: { dice: 'unknown token: "x"\nsecond line' },
      form: null,
    })
  })

  it.each([
    ['another check', 'checks[1].dice: bad', 2],
    ['a band', 'checks[2].bands[0].upTo: bad', 2],
    ['the bands as a whole', 'checks[2].bands: must have 1 to 10 items', 2],
    ['a property the form has no input for', 'checks[2].colour: is not a property', 2],
    ['a path that is not a check', 'index: must be 0 to 1', 2],
    ['a message with no path', 'The draft changed.', 2],
  ])('shows %s as a form message, whole', (_, text, index) => {
    expect(checkAnswer(text, index)).toEqual({ fields: {}, form: text })
  })
})
