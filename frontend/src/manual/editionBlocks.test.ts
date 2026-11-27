import { splitEditionBlocks } from './editionBlocks'

describe('splitEditionBlocks', () => {
  it('returns one common segment for a page without blocks', () => {
    expect(splitEditionBlocks('# Title\n\nSome text.\n')).toEqual([
      { edition: 'common', kind: 'markdown', text: '# Title\n\nSome text.' },
    ])
  })

  it('splits common and in-ogami segments in order', () => {
    const source = 'Before.\n\n:::in-ogami\nOpen Studio.\n:::\n\nAfter.\n'

    expect(splitEditionBlocks(source)).toEqual([
      { edition: 'common', kind: 'markdown', text: 'Before.' },
      { edition: 'in-ogami', kind: 'markdown', text: 'Open Studio.' },
      { edition: 'common', kind: 'markdown', text: 'After.' },
    ])
  })

  it('drops segments that hold only blank lines', () => {
    expect(splitEditionBlocks(':::in-ogami\nOnly here.\n:::\n')).toEqual([
      { edition: 'in-ogami', kind: 'markdown', text: 'Only here.' },
    ])
  })

  it('ignores markers inside fenced code blocks', () => {
    const source = 'Example:\n\n```\n:::in-ogami\n:::\n```\n\n~~~md\n:::\n~~~\n'

    const segments = splitEditionBlocks(source)

    expect(segments).toHaveLength(1)
    expect(segments[0]).toMatchObject({ edition: 'common' })
    expect(segments[0]).toMatchObject({ text: expect.stringContaining(':::in-ogami') as string })
  })

  it('ignores markers inside a fence in a CRLF source', () => {
    const source =
      'Example:\r\n\r\n```\r\n:::in-ogami\r\n:::\r\n```\r\n\r\n:::in-ogami\r\nHere.\r\n:::\r\n'

    const segments = splitEditionBlocks(source)

    expect(segments.map((segment) => segment.edition)).toEqual(['common', 'in-ogami'])
    expect(segments[0]).toMatchObject({ text: expect.stringContaining(':::in-ogami') as string })
    expect(segments[1]).toMatchObject({ text: 'Here.' })
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

    expect(splitEditionBlocks(source)).toEqual([
      { edition: 'in-ogami', kind: 'markdown', text: '``` a ```\nText.' },
    ])
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

  describe('the flow-diagram directive', () => {
    it('becomes its own segment between the text around it', () => {
      const source = 'Before.\n\n::flow-diagram{flow="cold-berth"}\n\nAfter.\n'

      expect(splitEditionBlocks(source)).toEqual([
        { edition: 'common', kind: 'markdown', text: 'Before.' },
        { edition: 'common', kind: 'flow-diagram', flow: 'cold-berth' },
        { edition: 'common', kind: 'markdown', text: 'After.' },
      ])
    })

    it('carries its phase when it names one', () => {
      const source = '::flow-diagram{flow="the-long-carry" phase="the-road"}\n'

      expect(splitEditionBlocks(source)).toEqual([
        { edition: 'common', kind: 'flow-diagram', flow: 'the-long-carry', phase: 'the-road' },
      ])
    })

    it('takes the edition it sits in', () => {
      const source = ':::in-ogami\n::flow-diagram{flow="cold-berth"}\n:::\n'

      expect(splitEditionBlocks(source)).toEqual([
        { edition: 'in-ogami', kind: 'flow-diagram', flow: 'cold-berth' },
      ])
    })

    it('stays text inside a code fence', () => {
      const source = '```\n::flow-diagram{flow="cold-berth"}\n::unknown\n```\n'

      const segments = splitEditionBlocks(source)

      expect(segments).toHaveLength(1)
      expect(segments[0]).toMatchObject({ kind: 'markdown' })
    })

    it('tolerates trailing spaces and a CRLF source', () => {
      expect(splitEditionBlocks('::flow-diagram{flow="cold-berth"}  \r\n')).toEqual([
        { edition: 'common', kind: 'flow-diagram', flow: 'cold-berth' },
      ])
    })

    it('throws on a directive it does not know, naming the line', () => {
      expect(() => splitEditionBlocks('Text\n::flow-map{flow="x"}\n')).toThrow(
        /line 2.*unknown directive "::flow-map\{flow="x"\}"/i,
      )
    })

    it.each([
      ['no attributes', '::flow-diagram'],
      ['empty braces', '::flow-diagram{}'],
      ['a missing flow', '::flow-diagram{phase="the-road"}'],
      ['an upper-case key', '::flow-diagram{flow="Cold-Berth"}'],
      ['single quotes', "::flow-diagram{flow='cold-berth'}"],
      ['an extra attribute', '::flow-diagram{flow="cold-berth" phase="the-ship" size="big"}'],
      ['attributes in the other order', '::flow-diagram{phase="the-ship" flow="cold-berth"}'],
      ['an unclosed brace', '::flow-diagram{flow="cold-berth"'],
      ['text after the directive', '::flow-diagram{flow="cold-berth"} caption'],
    ])('throws on a malformed directive: %s', (_, line) => {
      expect(() => splitEditionBlocks(`Text\n${line}\n`)).toThrow(/line 2.*malformed directive/i)
    })

    it('does not take a line that merely starts with two colons for a directive', () => {
      expect(splitEditionBlocks(':: not a directive\n')).toHaveLength(1)
    })
  })
})
