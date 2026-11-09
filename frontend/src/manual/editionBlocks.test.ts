import { splitEditionBlocks } from './editionBlocks'

describe('splitEditionBlocks', () => {
  it('returns one common segment for a page without blocks', () => {
    expect(splitEditionBlocks('# Title\n\nSome text.\n')).toEqual([
      { edition: 'common', text: '# Title\n\nSome text.' },
    ])
  })

  it('splits common and in-ogami segments in order', () => {
    const source = 'Before.\n\n:::in-ogami\nOpen Studio.\n:::\n\nAfter.\n'

    expect(splitEditionBlocks(source)).toEqual([
      { edition: 'common', text: 'Before.' },
      { edition: 'in-ogami', text: 'Open Studio.' },
      { edition: 'common', text: 'After.' },
    ])
  })

  it('drops segments that hold only blank lines', () => {
    expect(splitEditionBlocks(':::in-ogami\nOnly here.\n:::\n')).toEqual([
      { edition: 'in-ogami', text: 'Only here.' },
    ])
  })

  it('ignores markers inside fenced code blocks', () => {
    const source = 'Example:\n\n```\n:::in-ogami\n:::\n```\n\n~~~md\n:::\n~~~\n'

    const segments = splitEditionBlocks(source)

    expect(segments).toHaveLength(1)
    expect(segments[0]).toMatchObject({ edition: 'common' })
    expect(segments[0]?.text).toContain(':::in-ogami')
  })

  it('keeps a fence open until a closing fence at least as long', () => {
    const source = '````\n```\n:::\n````\n'

    expect(splitEditionBlocks(source)).toHaveLength(1)
  })

  it('throws on an unclosed block', () => {
    expect(() => splitEditionBlocks('Text\n:::in-ogami\nNever closed.\n')).toThrow(
      /line 2.*never closed/i,
    )
  })

  it('throws on a nested block', () => {
    expect(() => splitEditionBlocks(':::in-ogami\n:::in-ogami\n:::\n:::\n')).toThrow(
      /line 2.*nested/i,
    )
  })

  it('throws on a stray closing marker', () => {
    expect(() => splitEditionBlocks('Text\n:::\n')).toThrow(/line 2.*without an open block/i)
  })

  it('throws on a block marker it does not know', () => {
    expect(() => splitEditionBlocks(':::paper\nText\n:::\n')).toThrow(/line 1.*unknown/i)
  })

  it('throws on a block still open inside an unclosed fence', () => {
    expect(() => splitEditionBlocks(':::in-ogami\n```\ncode\n:::\n')).toThrow(/never closed/i)
  })

  it('does not take a four-space indented line for a fence', () => {
    expect(() => splitEditionBlocks(':::in-ogami\n    ```\ntext\n:::\n')).not.toThrow()
  })

  it('does not take backticks around an info string for a fence', () => {
    const source = ':::in-ogami\n``` a ```\nText.\n:::\n'

    expect(splitEditionBlocks(source)).toEqual([{ edition: 'in-ogami', text: '``` a ```\nText.' }])
  })

  it('does not close a fence with a line that has an info string', () => {
    expect(() => splitEditionBlocks('```\ncode\n```js\n:::\n')).toThrow(/never closed/i)
  })

  it('does not close a fence with a shorter run or the other character', () => {
    expect(() => splitEditionBlocks('````\n```\n~~~~\n:::\n')).toThrow(/never closed/i)
  })

  it('throws on an unclosed fence instead of hiding the markers after it', () => {
    expect(() => splitEditionBlocks('Text\n```\n:::in-ogami\nMore.\n')).toThrow(
      /line 2.*fence.*never closed/i,
    )
  })
})
