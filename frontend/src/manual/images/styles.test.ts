import { imagePrompt, imageStyleKeys, imageStyles } from './styles'

describe('the image styles', () => {
  it('knows the house style and the two games', () => {
    expect(imageStyleKeys).toEqual(['dogwatch', 'hard-country', 'house'])
  })

  it('builds a prompt from the preamble, a space and the subject', () => {
    const house = imageStyles.find((style) => style.key === 'house')

    expect(imagePrompt('house', 'A pencil.')).toBe(`${house?.preamble ?? ''} A pencil.`)
  })

  it('throws on a style it does not know, naming the known ones', () => {
    expect(() => imagePrompt('nope', 'x')).toThrow(/"nope".*dogwatch, hard-country, house/)
  })

  it.each(imageStyles)(
    'keeps the preamble of $key to one paragraph that never names the app',
    (style) => {
      expect(style.preamble).not.toContain('\n')
      expect(style.preamble).not.toMatch(/\b(Ogami|Studio|app)\b/i)
    },
  )
})
