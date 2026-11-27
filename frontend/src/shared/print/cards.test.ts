import { buildCards, CARD_CAPACITY, MAX_DECK_CARDS, paginateBack } from './cards'
import { readRelease } from './release'
import { miniRelease, step, type MiniRelease } from './testRelease'

const build = (raw: MiniRelease, options: Partial<Parameters<typeof buildCards>[1]> = {}) =>
  buildCards(readRelease(raw), { flow: 'mini', ...options })

const lines = (raw: MiniRelease) => build(raw).sceneCards[0]?.back[0]?.lines

const doomUp = { kind: 'tracker', tracker: 'doom', op: 'add', value: 1 }

describe('the generated wording of a Scene Type card', () => {
  it('prints the title and prompt of a step, and the options of a choice', () => {
    const raw = miniRelease([
      step('ask', 'prompt', { prompt: 'Say something.' }),
      step('pick', 'choice', { tip: 'Careful.', options: [{ label: 'Yes' }, { label: 'No' }] }),
      step('shop', 'shop', { tip: 'Spend.' }),
    ])

    expect(build(raw).sceneCards[0]).toMatchObject({
      name: 'Scene scene',
      purpose: 'A purpose.',
      tips: 'A tip.',
      back: [
        {
          heading: 'Setup',
          lines: [
            'Title of ask. Say something.',
            'Title of pick. Careful. Yes / No',
            'Title of shop. Spend.',
          ],
        },
      ],
    })
  })

  it('prints the bands of a check from their labels and effects', () => {
    const raw = miniRelease([
      step('roll', 'check', {
        prompt: 'Roll a d20.',
        outcomes: [
          { band: 'failure', effects: [doomUp] },
          {
            band: 'badly',
            effects: [
              doomUp,
              { kind: 'sheet', field: 'wind', op: 'subtract', value: { dice: '1d6' } },
            ],
          },
          { band: 'done', effects: [] },
          { band: 'lucky', effects: [{ kind: 'sheet', field: 'nerve', op: 'add', value: 2 }] },
        ],
      }),
    ])

    expect(lines(raw)).toEqual([
      'Title of roll. Roll a d20. Failing: Doom +1. Going badly: Doom +1 and lose d6 Wind. Lucky: regain 2 Nerve.',
    ])
  })

  it('leaves out an effect it cannot word, and keeps the rest', () => {
    const raw = miniRelease([
      step('roll', 'check', {
        outcomes: [
          {
            band: 'done',
            effects: [{ kind: 'nextScene', sceneType: 'scene' }, { ...doomUp, op: 'set' }, doomUp],
          },
          {
            band: 'wrong',
            effects: [{ kind: 'sheet', field: 'wind', op: 'add', value: { dice: '2d6kh1' } }],
          },
        ],
      }),
    ])

    expect(lines(raw)).toEqual(['Title of roll. Done: Doom +1.'])
  })

  it('replaces {step:} and {fact:} with the step title and the Fact slot label', () => {
    const raw = miniRelease([
      step('first', 'prompt', { prompt: 'Name it.' }),
      step('ask', 'prompt', { prompt: 'Is {step:first} what {fact:sin} says?' }),
    ])

    expect(lines(raw)?.[1]).toBe('Title of ask. Is Title of first what The Sin says?')
  })

  it.each([
    ['a step that is not in the Scene Type', 'Is {step:gone} here?', /scene\.ask: \{step:gone\}/],
    ['a Fact slot the release lacks', 'Is {fact:gone} here?', /scene\.ask: \{fact:gone\}/],
  ])('throws on %s', (_name, prompt, message) => {
    expect(() => build(miniRelease([step('ask', 'prompt', { prompt })]))).toThrow(message)
  })

  it('prints app wording and other placeholders as they are when no guard is plugged in', () => {
    const raw = miniRelease([step('ask', 'prompt', { prompt: 'Open the app, {picked}.' })])

    expect(lines(raw)).toEqual(['Title of ask. Open the app, {picked}.'])
  })

  it('lets the caller override a step, drop it, or refuse it, and transform every text', () => {
    const raw = miniRelease([step('a', 'prompt'), step('b', 'prompt'), step('c', 'prompt')])
    const texts: string[] = []
    const cards = build(raw, {
      wording: (id) => (id === 'scene.a' ? 'Over.' : id === 'scene.b' ? null : undefined),
      tips: () => 'Other tip.',
      text: (text, where) => {
        texts.push(`${where}: ${text}`)
        return text.toUpperCase()
      },
    })

    expect(cards.sceneCards[0]).toMatchObject({ name: 'SCENE SCENE', tips: 'OTHER TIP.' })
    expect(cards.sceneCards[0]?.back[0]?.lines).toEqual(['OVER.', 'TITLE OF C.'])
    expect(texts).toContain('scene.a: Over.')
    expect(() =>
      build(raw, {
        wording: () => {
          throw new Error('no wording')
        },
      }),
    ).toThrow('no wording')
  })

  it('throws on a flow the release does not have', () => {
    expect(() => build(miniRelease(), { flow: 'other' })).toThrow(/"other"/)
  })
})

describe('the decks', () => {
  it('makes a deck of the table of each oracle selection, with a copy per weight', () => {
    const { decks } = build(miniRelease())

    expect(decks).toHaveLength(1)
    expect(decks[0]).toMatchObject({ key: 'deck', name: 'The deck', perEntry: false })
    expect(decks[0]?.cards).toEqual([
      { kind: 'entry', text: 'One', starts: 'Scene scene' },
      { kind: 'entry', text: 'One', starts: 'Scene scene' },
      { kind: 'entry', text: 'One', starts: 'Scene scene' },
      { kind: 'entry', text: 'Two', starts: null },
    ])
  })

  it.each([0, -1, 1.5])('refuses an entry with a weight of %s, as the reader does', (weight) => {
    const release = readRelease(miniRelease())
    const entry = release.tables[0]?.entries[0]
    if (entry === undefined) {
      throw new Error('The mini release has an entry.')
    }
    entry.weight = weight

    expect(() => buildCards(release, { flow: 'mini' })).toThrow(
      new RegExp(
        `^deck: the entry "One" has a weight of ${String(weight)}, expected a whole number of at least 1\\.$`,
      ),
    )
  })

  it('adds any other table the caller ticks, once', () => {
    const { decks } = build(miniRelease(), { deckTables: ['luck', 'deck'] })

    expect(decks.map((deck) => deck.key)).toEqual(['deck', 'luck'])
    expect(decks[1]?.cards.map((card) => card.text)).toEqual(['Good', 'Bad'])
  })

  it('makes no deck of a flow with no oracle selection', () => {
    const raw = miniRelease()
    raw.flows[0]?.phases.shift()

    expect(build(raw).decks).toEqual([])
  })

  it('gives a ranged table a copy per number of its range', () => {
    const raw = miniRelease()
    raw.oracles.tables[1]?.entries.splice(
      0,
      2,
      { text: 'Low', min: 1, max: 3 } as never,
      { text: 'High', min: 4, max: 4 } as never,
    )

    const { decks } = build(raw, { deckTables: ['luck'] })

    expect(decks[1]?.cards.map((card) => card.text)).toEqual(['Low', 'Low', 'Low', 'High'])
  })

  it('prints a card per entry, with its range, when the deck would pass 54 cards', () => {
    const raw = miniRelease()
    raw.oracles.tables[0]?.entries.splice(
      0,
      2,
      { text: 'Big', weight: 50, sceneType: 'scene' },
      { text: 'Small', weight: 5 } as never,
      { text: 'Last', weight: 1 } as never,
    )

    const { decks } = build(raw)

    expect(50 + 5 + 1).toBeGreaterThan(MAX_DECK_CARDS)
    expect(decks[0]?.perEntry).toBe(true)
    expect(decks[0]?.cards).toEqual([
      { kind: 'entry', text: 'Big', starts: 'Scene scene', range: '1-50' },
      { kind: 'entry', text: 'Small', starts: null, range: '51-55' },
      { kind: 'entry', text: 'Last', starts: null, range: '56' },
    ])
  })

  it('numbers a ranged table by its own ranges when it prints a card per entry', () => {
    const raw = miniRelease()
    raw.oracles.tables[0]?.entries.splice(
      0,
      2,
      { text: 'Low', min: 1, max: 60 } as never,
      { text: 'High', min: 61, max: 100 } as never,
    )

    expect(build(raw).decks[0]?.cards.map((card) => card.range)).toEqual(['1-60', '61-100'])
  })

  it('applies the text transform to the entries, and throws on a table or Scene Type that is missing', () => {
    expect(build(miniRelease(), { text: (text) => `[${text}]` }).decks[0]?.cards[3]?.text).toBe(
      '[Two]',
    )
    expect(() => build(miniRelease(), { deckTables: ['gone'] })).toThrow(/"gone"/)
    const raw = miniRelease()
    Object.assign(raw.oracles.tables[0]?.entries[0] ?? {}, { sceneType: 'gone' })
    expect(() => build(raw)).toThrow(/"gone"/)
  })
})

describe('the continuation of a long Scene Type card', () => {
  const long = (count: number, length = 10) =>
    miniRelease(
      Array.from({ length: count }, (_, index) =>
        step(`s${String(index)}`, 'prompt', { prompt: 'x'.repeat(length) }),
      ),
    )

  it('keeps a card whose back fits on one card', () => {
    const cards = build(long(3))

    expect(cards.sceneCards).toHaveLength(1)
    expect(cards.continued).toEqual([])
  })

  it('continues on a "(cont.)" card and lists the Scene Type', () => {
    const cards = build(long(40))

    expect(cards.sceneCards.map((card) => card.name)).toEqual([
      'Scene scene',
      'Scene scene (cont.)',
    ])
    expect(cards.sceneCards[1]).toMatchObject({
      continues: 'scene',
      tips: '',
      back: [{ heading: 'Setup' }],
    })
    expect(cards.continued).toEqual([{ sceneType: 'scene', name: 'Scene scene', cards: 2 }])
    const printed = cards.sceneCards.flatMap((card) =>
      card.back.flatMap((section) => section.lines),
    )
    expect(printed).toHaveLength(40)
  })

  it('never splits when no capacity is given, and uses the room of the card size', () => {
    expect(build(long(40), { capacity: null }).sceneCards).toHaveLength(1)
    expect(build(long(40), { capacity: CARD_CAPACITY.a6 }).sceneCards).toHaveLength(1)
  })

  it('counts wrapped lines, not steps', () => {
    const wide = build(long(6, CARD_CAPACITY.poker.charsPerLine * 5))

    expect(wide.sceneCards.length).toBeGreaterThan(1)
  })
})

describe('paginateBack', () => {
  const capacity = { lines: 6, charsPerLine: 10 }
  const section = (heading: 'Setup' | 'Play', ...lines: string[]) => ({ heading, lines })

  it('keeps a heading with its first line, and repeats it on the next page', () => {
    const pages = paginateBack([section('Setup', 'aaaa', 'bbbb', 'cccc')], capacity)

    // The heading and its gap take two lines, each text line one: all three fit.
    expect(pages.map((page) => page.flatMap((s) => s.lines))).toEqual([['aaaa', 'bbbb', 'cccc']])
    const more = paginateBack([section('Setup', 'a'.repeat(30), 'b'.repeat(30))], capacity)
    expect(more.map((page) => page[0]?.heading)).toEqual(['Setup', 'Setup'])
  })

  it('puts a line longer than a page on a page of its own', () => {
    const pages = paginateBack([section('Setup', 'x'.repeat(200), 'y')], capacity)

    expect(pages.map((page) => page.flatMap((s) => s.lines.map((line) => line.length)))).toEqual([
      [200],
      [1],
    ])
  })

  it('starts the next section on the same page when it fits', () => {
    const pages = paginateBack([section('Setup', 'a'), section('Play', 'b')], capacity)

    expect(pages).toHaveLength(1)
    expect(pages[0]?.map((s) => s.heading)).toEqual(['Setup', 'Play'])
  })

  it('gives one empty page for an empty back', () => {
    expect(paginateBack([], capacity)).toEqual([[]])
  })
})

describe('legacy releases', () => {
  it('builds a release with no tips, no phases and no images', () => {
    const raw = miniRelease()
    Object.assign(raw.sceneTypes[0] ?? {}, { tips: undefined })
    raw.flows[0]?.phases.splice(0)

    const cards = build(raw)

    expect(cards.sceneCards[0]?.tips).toBe('')
    expect(cards.decks).toEqual([])
  })
})
