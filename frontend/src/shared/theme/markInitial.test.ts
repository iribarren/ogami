import { markInitial } from './markInitial'

describe('markInitial', () => {
  it.each([
    ['The Lost Mine', 'T'],
    ['  ogami', 'O'],
    ['ébène', 'É'],
    ['7th Sea', '7'],
    ['🐉 Dragon Heist', 'D'],
    ['"Quoted" start', 'Q'],
    ['Ñandú', 'Ñ'],
  ])('takes the first letter or digit of %j', (name, expected) => {
    expect(markInitial(name)).toBe(expected)
  })

  it.each([[''], ['   '], ['🐉🐉'], ['---']])('falls back to a glyph for %j', (name) => {
    expect(markInitial(name)).toBe('✦')
  })

  it('does not depend on the runtime locale', () => {
    // A stand-in for a locale (Turkish dotless i) that upper-cases differently: the seal must not follow it.
    const spy = vi.spyOn(String.prototype, 'toLocaleUpperCase').mockReturnValue('LOCALE')

    try {
      expect(markInitial('ida')).toBe('I')
    } finally {
      spy.mockRestore()
    }
  })
})
