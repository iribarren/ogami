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

  describe('the image directive', () => {
    const attrs =
      'file="hc-ford.webp" style="hard-country" size="half" alt="A ford." subject="A ford at dusk."'
    const valid = `::image{${attrs}}`

    it('becomes its own segment between the text around it', () => {
      expect(splitEditionBlocks(`Before.\n\n${valid}\n\nAfter.\n`)).toEqual([
        { edition: 'common', kind: 'markdown', text: 'Before.' },
        {
          edition: 'common',
          kind: 'image',
          file: 'hc-ford.webp',
          style: 'hard-country',
          size: 'half',
          alt: 'A ford.',
          subject: 'A ford at dusk.',
        },
        { edition: 'common', kind: 'markdown', text: 'After.' },
      ])
    })

    it('takes the edition it sits in', () => {
      const [segment] = splitEditionBlocks(`:::in-ogami\n${valid}\n:::\n`)

      expect(segment).toMatchObject({ edition: 'in-ogami', kind: 'image' })
    })

    it('stays text inside a code fence', () => {
      const segments = splitEditionBlocks(`\`\`\`\n${valid}\n::image{broken}\n\`\`\`\n`)

      expect(segments).toHaveLength(1)
      expect(segments[0]).toMatchObject({ kind: 'markdown' })
    })

    it('tolerates trailing spaces and a CRLF source', () => {
      expect(splitEditionBlocks(`${valid}  \r\n`)).toHaveLength(1)
    })

    it.each(['banner', 'half', 'spot'])('accepts the size %s', (size) => {
      const [segment] = splitEditionBlocks(valid.replace('size="half"', `size="${size}"`))

      expect(segment).toMatchObject({ kind: 'image', size })
    })

    it.each(['webp', 'png', 'jpg'])('accepts the extension %s', (extension) => {
      const [segment] = splitEditionBlocks(valid.replace('.webp', `.${extension}`))

      expect(segment).toMatchObject({ kind: 'image', file: `hc-ford.${extension}` })
    })

    it.each([
      ['no attributes', '::image'],
      ['empty braces', '::image{}'],
      ['a missing subject', '::image{file="a.webp" style="house" size="spot" alt="A."}'],
      [
        'attributes in the other order',
        `::image{style="house" ${attrs.replace(' style="hard-country"', '')}}`,
      ],
      ['single quotes', `::image{${attrs.replace(/"/g, "'")}}`],
      ['an extra attribute', `::image{${attrs} extra="x"}`],
      ['an unclosed brace', `::image{${attrs}`],
      ['text after the directive', `${valid} caption`],
      ['an empty alt', valid.replace('alt="A ford."', 'alt=""')],
      ['an empty subject', valid.replace('subject="A ford at dusk."', 'subject=""')],
      ['an unknown size', valid.replace('size="half"', 'size="huge"')],
      ['an upper-case file name', valid.replace('hc-ford.webp', 'HC-ford.webp')],
      ['a file name without an extension', valid.replace('hc-ford.webp', 'hc-ford')],
      ['an unsupported extension', valid.replace('.webp', '.gif')],
      ['a path in the file name', valid.replace('hc-ford.webp', '../hc-ford.webp')],
    ])('throws on a malformed directive: %s', (_, line) => {
      expect(() => splitEditionBlocks(`Text\n${line}\n`)).toThrow(
        /line 2.*malformed directive.*expected ::image\{file="…" style="…" size="banner\|half\|spot" alt="…" subject="…"\}/i,
      )
    })

    it('throws on a style it does not know, naming the known ones', () => {
      expect(() =>
        splitEditionBlocks(`Text\n${valid.replace('hard-country', 'no-such-style')}\n`),
      ).toThrow(/line 2.*unknown image style "no-such-style".*dogwatch.*hard-country.*house/i)
    })
  })
})
