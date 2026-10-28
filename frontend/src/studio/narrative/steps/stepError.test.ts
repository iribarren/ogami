import { clientStepErrors, mapStepError } from './stepError'

const base = 'sceneTypes[2].play'

describe('mapping a server error to a step', () => {
  it('finds the card and the field', () => {
    expect(mapStepError(base, 'sceneTypes[2].play[1].title: must not be blank')).toEqual({
      index: 1,
      band: null,
      effect: null,
      field: 'title',
      message: 'must not be blank',
    })
  })

  it('finds the band and its field', () => {
    expect(mapStepError(base, 'sceneTypes[2].play[1].bands[0].upTo: must increase')).toEqual({
      index: 1,
      band: 0,
      effect: null,
      field: 'upTo',
      message: 'must increase',
    })
  })

  it('finds the check outcome of a special result and its field', () => {
    expect(
      mapStepError(base, 'sceneTypes[2].play[1].outcomes[2].special: this check cannot produce it'),
    ).toEqual({
      index: 1,
      band: null,
      effect: null,
      item: 2,
      field: 'special',
      message: 'this check cannot produce it',
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
      effect: null,
      field: null,
      message: 'unknown kind "x"',
    })
    expect(mapStepError(base, 'sceneTypes[2].play[2].bands[1]: needs a limit')).toMatchObject({
      index: 2,
      band: 1,
      field: null,
    })
  })

  it('finds the effect and its field, on a step and on a band', () => {
    expect(mapStepError(base, 'sceneTypes[2].play[1].effects[2].value: unknown tracker')).toEqual({
      index: 1,
      band: null,
      effect: 2,
      field: 'value',
      message: 'unknown tracker',
    })
    expect(mapStepError(base, 'sceneTypes[2].play[0].bands[3].effects[1].tags[0]: no')).toEqual({
      index: 0,
      band: 3,
      effect: 1,
      field: 'tags',
      message: 'no',
    })
    expect(mapStepError(base, 'sceneTypes[2].play[0].effects[4]: bad kind')).toMatchObject({
      effect: 4,
      field: null,
    })
    expect(mapStepError(base, 'sceneTypes[2].play[0].effects: at most 10 effects')).toMatchObject({
      effect: null,
      field: 'effects',
    })
  })

  it('keeps a nested field as the first one and the message whole', () => {
    expect(mapStepError(base, 'sceneTypes[2].play[0].count.of: bad: really')).toEqual({
      index: 0,
      band: null,
      effect: null,
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
        effect: null,
        field: null,
        message: text,
      })
    }
  })
})

describe('mapping a server error about branches and options', () => {
  const map = (path: string) => mapStepError(base, `${base}[1]${path}: no good`)

  it.each([
    ['.branches.yes.next', { slot: 'yes', field: 'next', effect: null }],
    [
      '.branches.exceptionalNo.effects[2].field',
      { slot: 'exceptionalNo', field: 'field', effect: 2 },
    ],
    ['.branches.no', { slot: 'no', field: null, effect: null }],
    ['.otherwise.effects[0]', { slot: 'otherwise', field: null, effect: 0 }],
    ['.otherwise', { slot: 'otherwise', field: null, effect: null }],
    ['.branches[3].entry', { item: 3, field: 'entry', effect: null }],
    ['.branches[3].effects[1].tracker', { item: 3, field: 'tracker', effect: 1 }],
    ['.options[0].label', { item: 0, field: 'label', effect: null }],
    ['.options[4].next', { item: 4, field: 'next', effect: null }],
    ['.branches.found.next', { slot: 'found', field: 'next', effect: null }],
    ['.branches.none.effects[1].value', { slot: 'none', field: 'value', effect: 1 }],
    ['.outcomes[2].band', { item: 2, field: 'band', effect: null }],
    ['.outcomes[0].next', { item: 0, field: 'next', effect: null }],
    ['.outcomes[1].effects[0].field', { item: 1, field: 'field', effect: 0 }],
    ['.outcomes[3]', { item: 3, field: null, effect: null }],
    ['.count.min', { field: 'count.min', effect: null }],
    ['.count.max', { field: 'count.max', effect: null }],
    ['.difficulty', { field: 'difficulty', effect: null }],
    ['.difficulty.tracker', { field: 'difficulty', effect: null }],
  ])('reads %s', (path, expected) => {
    expect(map(path)).toMatchObject({ index: 1, band: null, ...expected })
  })

  it('leaves the step fields on the step and the list-level ones as fields', () => {
    for (const field of [
      'oracle',
      'likelihood',
      'table',
      'skip',
      'options',
      'branches',
      'outcomes',
      'check',
      'of',
      'mode',
    ]) {
      const error = map(`.${field}`)
      expect(error).toMatchObject({ index: 1, field })
      expect(error.slot).toBeUndefined()
      expect(error.item).toBeUndefined()
    }
  })
})

describe('mapping a shop step error', () => {
  it.each([
    ['offer', 'offer'],
    ['offer.gear[1]', 'offer.gear'],
    ['offer.categories[0]', 'offer.categories'],
    ['stock', 'stock'],
    ['stock.dice', 'stock.dice'],
    ['sellRate', 'sellRate'],
  ])('puts %s on the %s input', (path, field) => {
    expect(mapStepError(base, `sceneTypes[2].play[1].${path}: unknown`)).toEqual({
      index: 1,
      band: null,
      effect: null,
      field,
      message: 'unknown',
    })
  })

  it('reads the whole numbers the stock and the sell rate refuse', () => {
    expect(clientStepErrors({ '2.stock': 'Use a whole number.' })).toEqual([
      { index: 2, band: null, effect: null, field: 'stock', message: 'Use a whole number.' },
    ])
  })
})

describe('mapping the whole numbers a step list refuses to a step', () => {
  it('reads the keys stepsToItems gives, on a step, a band and an effect of either', () => {
    expect(
      clientStepErrors({
        '1.bands.0.upTo': 'Use a whole number.',
        '0.effects.2.value': 'Use a whole number.',
        '1.bands.1.effects.0.value': 'Use a whole number.',
        '2.count.max': 'Use a whole number.',
        '3.difficulty': 'Use a whole number.',
        '3.outcomes.1.effects.0.value': 'Use a whole number.',
      }),
    ).toEqual([
      { index: 1, band: 0, effect: null, field: 'upTo', message: 'Use a whole number.' },
      { index: 0, band: null, effect: 2, field: 'value', message: 'Use a whole number.' },
      { index: 1, band: 1, effect: 0, field: 'value', message: 'Use a whole number.' },
      { index: 2, band: null, effect: null, field: 'count.max', message: 'Use a whole number.' },
      { index: 3, band: null, effect: null, field: 'difficulty', message: 'Use a whole number.' },
      {
        index: 3,
        band: null,
        effect: 0,
        item: 1,
        field: 'value',
        message: 'Use a whole number.',
      },
    ])
  })
})
