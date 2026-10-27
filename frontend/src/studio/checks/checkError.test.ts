import { checkAnswer } from './checkError'

describe('checkAnswer', () => {
  it.each([
    ['key', 'key'],
    ['label', 'label'],
    ['hint', 'hint'],
    ['dice', 'dice'],
    ['modifier', 'modifier'],
    ['pool.count', 'poolCount'],
    ['pool.count.formula', 'poolCount'],
    ['pool.sides', 'poolSides'],
    ['pool.successAt', 'poolSuccessAt'],
    ['pool.criticals', 'poolCriticalsBonus'],
    ['pool.criticals.bonus', 'poolCriticalsBonus'],
    ['pool.hunger', 'poolHunger'],
    ['pool.hunger.count', 'poolHunger'],
    ['pool.hunger.count.formula', 'poolHunger'],
    ['push.label', 'pushLabel'],
    ['push.maxDice', 'pushMaxDice'],
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

  it.each([
    ['push.effects', null, 'effects'],
    ['push.effects[3]', 3, null],
    ['push.effects[1].field', 1, 'field'],
    ['push.effects[1].op', 1, 'op'],
    ['push.effects[0].value', 0, 'value'],
    ['push.effects[0].value.formula', 0, 'value'],
    ['push.effects[2].tracker', 2, 'tracker'],
  ])('puts checks[2].%s on the effect of the push', (path, effect, field) => {
    expect(checkAnswer(`checks[2].${path}: is wrong`, 2)).toEqual({
      fields: {
        pushEffects: [{ index: null, band: null, effect, field, message: 'is wrong' }],
      },
      form: null,
    })
  })

  it.each(['push', 'push.colour', 'push.effects.x'])(
    'shows checks[2].%s as a form message, whole',
    (path) => {
      const text = `checks[2].${path}: is wrong`

      expect(checkAnswer(text, 2)).toEqual({ fields: {}, form: text })
    },
  )

  it('keeps the message whole, colons and line breaks included', () => {
    expect(checkAnswer('checks[0].dice: unknown token: "x"\nsecond line', 0)).toEqual({
      fields: { dice: 'unknown token: "x"\nsecond line' },
      form: null,
    })
  })

  it.each([
    ['another check', 'checks[1].dice: bad', 2],
    ['a band property the form has no input for', 'checks[2].bands[0].colour: bad', 2],
    ['a band of another check', 'checks[1].bands[0].key: bad', 2],
    ['a property the form has no input for', 'checks[2].colour: is not a property', 2],
    ['a path that is not a check', 'index: must be 0 to 1', 2],
    ['a message with no path', 'The draft changed.', 2],
  ])('shows %s as a form message, whole', (_, text, index) => {
    expect(checkAnswer(text, index)).toEqual({ fields: {}, form: text })
  })

  it.each(['key', 'label', 'upTo'])('puts checks[2].bands[1].%s on that band', (name) => {
    expect(checkAnswer(`checks[2].bands[1].${name}: is wrong`, 2)).toEqual({
      fields: { band: { 1: { [name]: 'is wrong' } } },
      form: null,
    })
  })

  it.each(['formula', 'tracker'])('puts a refusal of the %s bound on the bound input', (kind) => {
    expect(checkAnswer(`checks[0].bands[3].upTo.${kind}: Unknown field "x".`, 0)).toEqual({
      fields: { band: { 3: { upTo: 'Unknown field "x".' } } },
      form: null,
    })
  })

  it('puts a refusal of the bands as a whole on the bands', () => {
    expect(checkAnswer('checks[2].bands: must have 1 to 10 items', 2)).toEqual({
      fields: { bands: 'must have 1 to 10 items' },
      form: null,
    })
  })
})
