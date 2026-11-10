import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { buildFlowCards } from './flowCards'
import type { PaperFlow } from './paperFlow'
import { coldBerthPaper } from './cold-berth.paper'
import { theLongCarryPaper } from './the-long-carry.paper'

// The presets PHP loads too (backend/presets/). A missing file fails the suite: the node
// container mounts them, see compose.yaml.
const preset = (name: string): unknown =>
  JSON.parse(readFileSync(resolve(process.cwd(), `../backend/presets/${name}.json`), 'utf8'))

const step = (key: string, kind: string, extra: object = {}) => ({
  key,
  kind,
  title: `Title of ${key}`,
  ...extra,
})

/** A preset of one Scene Type and one table, small enough to break one field at a time. */
function mini(steps: object[] = [step('ask', 'prompt', { prompt: 'Say something.' })]) {
  return {
    gameSystem: { name: 'Mini', credits: { license: 'CC BY-SA 4.0' } },
    trackers: [{ key: 'doom', name: 'Doom', hint: 'Near.', kind: 'clock', segments: 6 }],
    factSlots: [{ key: 'sin', label: 'The Sin' }],
    oracles: {
      likelihood: [{ key: 'yes-no' }],
      tables: [
        { key: 'deck', name: 'Deck', entries: [{ text: 'One', weight: 2, sceneType: 'scene' }] },
        { key: 'luck', name: 'Luck', entries: [{ text: 'Good' }, { text: 'Bad' }] },
      ],
    },
    sceneTypes: [
      {
        key: 'scene',
        name: 'Scene',
        purpose: 'A purpose.',
        tips: 'A tip.',
        setup: steps,
        play: [],
        closing: [],
      },
    ],
    flows: [
      {
        key: 'mini',
        name: 'Mini',
        description: 'A question?',
        oracles: ['yes-no', 'luck'],
        trackers: ['doom'],
      },
    ],
  }
}

const paper = (extra: Partial<PaperFlow> = {}): PaperFlow => ({
  flow: 'mini',
  steps: {},
  deck: {
    kind: 'table',
    table: 'deck',
    back: 'The deck',
    finale: { sceneType: 'scene', note: 'Last.' },
  },
  rules: {
    slots: { sin: 'Written as you go.' },
    sections: [{ heading: 'Rule', lines: ['Do it.'] }],
  },
  ...extra,
})

describe('buildFlowCards', () => {
  it('writes a card line from the title and prompt of a step, and its options', () => {
    const cards = buildFlowCards(
      mini([
        step('ask', 'prompt', { prompt: 'Say something.' }),
        step('pick', 'choice', { tip: 'Careful.', options: [{ label: 'Yes' }, { label: 'No' }] }),
      ]),
      paper(),
    )

    expect(cards.sceneCards[0]).toMatchObject({
      name: 'Scene',
      purpose: 'A purpose.',
      tips: 'A tip.',
      back: [
        {
          heading: 'Setup',
          lines: ['Title of ask. Say something.', 'Title of pick. Careful. Yes / No'],
        },
      ],
    })
  })

  it('uses the paper wording of a step, a null entry drops it, and a tip override wins', () => {
    const cards = buildFlowCards(
      mini([
        step('ask', 'prompt', { prompt: 'Say.' }),
        step('gone', 'prompt', { prompt: 'Skip.' }),
      ]),
      paper({
        steps: { 'scene.ask': 'On paper.', 'scene.gone': null },
        tips: { scene: 'New tip.' },
      }),
    )

    expect(cards.sceneCards[0]?.tips).toBe('New tip.')
    expect(cards.sceneCards[0]?.back[0]?.lines).toEqual(['On paper.'])
  })

  it.each(['character', 'shop', 'check', 'condition'])(
    'throws on a %s step with no paper wording, naming the step',
    (kind) => {
      expect(() => buildFlowCards(mini([step('thing', kind)]), paper())).toThrow(/scene\.thing/)
    },
  )

  it('throws on a step kind it has no rule for', () => {
    expect(() => buildFlowCards(mini([step('thing', 'teleport')]), paper())).toThrow(/scene\.thing/)
  })

  it('throws on wording for a step or a Scene Type that no longer exists', () => {
    expect(() => buildFlowCards(mini(), paper({ steps: { 'scene.old': 'x' } }))).toThrow(
      /scene\.old/,
    )
    expect(() => buildFlowCards(mini(), paper({ tips: { old: 'x' } }))).toThrow(/"old"/)
  })

  it.each([
    ['names the app', { prompt: 'Open it in the app.' }],
    ['names Studio', { tip: 'Edit it in Studio.' }],
    ['says leave it empty', { tip: 'Leave it empty to move on.' }],
    ['holds a placeholder', { prompt: 'Who is in {picked}?' }],
  ])('throws on card text that %s', (_name, fields) => {
    expect(() => buildFlowCards(mini([step('ask', 'prompt', fields)]), paper())).toThrow(
      /scene\.ask/,
    )
  })

  it('also checks the paper wording itself', () => {
    expect(() =>
      buildFlowCards(mini(), paper({ steps: { 'scene.ask': 'Use the app.' } })),
    ).toThrow()
  })

  it('throws on a preset with a missing or malformed field', () => {
    const noName = mini()
    Object.assign(noName.sceneTypes[0] ?? {}, { name: 7 })

    expect(() => buildFlowCards(noName, paper())).toThrow(/name/)
    expect(() => buildFlowCards({}, paper())).toThrow()
    expect(() => buildFlowCards(mini(), paper({ flow: 'other' }))).toThrow(/other/)
  })

  it('prints one deck card per copy, plus the finale', () => {
    const { deck } = buildFlowCards(mini(), paper())

    expect(deck).toEqual([
      { kind: 'entry', text: 'One', starts: 'Scene' },
      { kind: 'entry', text: 'One', starts: 'Scene' },
      { kind: 'finale', text: 'Scene: A purpose.', starts: 'Scene', note: 'Last.' },
    ])
  })

  it('prints blank compartment cards when the flow has no deck', () => {
    const { deck, deckBack } = buildFlowCards(
      mini(),
      paper({ deck: { kind: 'compartments', count: 3, back: 'The ship' } }),
    )

    expect(deck).toEqual([
      { kind: 'compartment' },
      { kind: 'compartment' },
      { kind: 'compartment' },
    ])
    expect(deckBack).toBe('The ship')
  })

  it('numbers the other tables of the flow, leaving out the likelihood oracle and the deck', () => {
    const { tables } = buildFlowCards(mini(), paper())

    expect(tables).toEqual([
      {
        name: 'Luck',
        die: 4,
        rollAgainAbove: 2,
        rows: [
          { range: '1', text: 'Good', effects: [] },
          { range: '2', text: 'Bad', effects: [] },
        ],
      },
    ])
  })

  it('throws on a flow oracle that is neither a table nor a likelihood oracle', () => {
    const raw = mini()
    raw.flows[0]?.oracles.push('missing')

    expect(() => buildFlowCards(raw, paper())).toThrow(/missing/)
  })

  it('reads the Trackers and Fact slots off the preset, and ends with the credit', () => {
    const { rulesSheet } = buildFlowCards(mini(), paper())

    expect(rulesSheet.title).toBe('Mini')
    expect(rulesSheet.question).toBe('A question?')
    expect(rulesSheet.sections.map((section) => section.heading)).toEqual([
      'Trackers',
      'Fact slots',
      'Rule',
    ])
    expect(rulesSheet.sections[0]?.lines[0]).toMatch(/^Doom: a clock of 6 boxes\. Near\.$/)
    expect(rulesSheet.sections[1]?.lines).toEqual(['The Sin: Written as you go.'])
    expect(rulesSheet.credit).toMatch(/Mini.*CC BY-SA 4\.0/)
  })

  it('throws when a Fact slot has no paper wording or the wording has no slot', () => {
    expect(() => buildFlowCards(mini(), paper({ rules: { slots: {}, sections: [] } }))).toThrow(
      /sin/,
    )
    expect(() =>
      buildFlowCards(mini(), paper({ rules: { slots: { sin: 'x', old: 'y' }, sections: [] } })),
    ).toThrow(/old/)
  })
})

interface Raw {
  flows: { phases: { sceneClosing?: { bands: { upTo?: number }[] }[] }[] }[]
  sceneTypes: { key: string; play: { kind: string; bands?: { upTo?: number }[] }[] }[]
}

describe.each<[string, PaperFlow, string, number, [string, string]]>([
  ['hard-country', theLongCarryPaper, 'The Long Carry', 10, ['Winter', 'Burden']],
  ['dogwatch', coldBerthPaper, 'Cold Berth', 11, ['Threat', 'Clues']],
])('the %s cards', (name, paperFlow, flowName, sceneTypes, [clock, counter]) => {
  const raw = preset(name) as Raw
  const cards = buildFlowCards(raw, paperFlow)
  const sheet = cards.rulesSheet.sections.flatMap((section) => section.lines).join('\n')

  it('has a card for every Scene Type, each with something on its back', () => {
    expect(cards.sceneCards).toHaveLength(sceneTypes)
    for (const card of cards.sceneCards) {
      expect(card.back.length, card.name).toBeGreaterThan(0)
    }
  })

  it('gives the rules sheet the flow and no tables the likelihood oracle', () => {
    expect(cards.rulesSheet.title).toBe(flowName)
    expect(cards.tables.map((table) => table.name)).not.toContain('Yes/no question')
  })

  it('writes the closing check with the numbers of the preset', () => {
    const closing = raw.flows[0]?.phases.flatMap((phase) => phase.sceneClosing ?? []) ?? []
    const [far = 0, near = 0] = closing[0]?.bands.map((band) => band.upTo ?? 0) ?? []

    expect(sheet).toContain(`${String(far)} or less`)
    expect(sheet).toContain(`${String(far + 1)} to ${String(near)}`)
    expect(sheet).toContain(`${String(near + 1)} ends it`)
  })

  it('writes the cut points of the endings with the numbers of the preset', () => {
    const conditions = raw.sceneTypes
      .find((st) => st.key === 'epilogue')
      ?.play.filter((step) => step.kind === 'condition')
    const [first, second] = [0, 1].map((at) => conditions?.[at]?.bands?.[0]?.upTo ?? -1)

    expect(sheet).toContain(`${clock} ${String(first)} or less`)
    expect(sheet).toContain(`${counter} ${String(second)} or less`)
  })
})

describe('The Long Carry cards', () => {
  const cards = buildFlowCards(preset('hard-country'), theLongCarryPaper)

  it('prints the road deck: thirteen copies and the Shrine Gate to seed', () => {
    expect(cards.deck).toHaveLength(14)
    const finales = cards.deck.filter((card) => card.kind === 'finale')

    expect(finales).toHaveLength(1)
    expect(finales[0]).toMatchObject({ starts: 'The Shrine Gate' })
    expect(JSON.stringify(finales[0])).toMatch(/bottom five/)
    expect(cards.deckBack).toBe('The road deck')
  })

  it('numbers Road Risks on a d10 and rolls again above 9', () => {
    expect(cards.tables.map((table) => table.name)).toEqual([
      'Road Risks',
      'Strangers',
      'Omens',
      'Ruins',
    ])
    expect(cards.tables[0]).toMatchObject({ die: 10, rollAgainAbove: 9 })
    expect(cards.tables[1]).toMatchObject({ die: 8, rollAgainAbove: null })
  })

  it('prints what a Road Risks entry does as well as what it says', () => {
    const frost = cards.tables[0]?.rows.find((row) => row.text.startsWith('A hard frost'))

    expect(frost?.effects).toEqual(['Winter +1'])
  })
})

describe('Cold Berth cards', () => {
  const cards = buildFlowCards(preset('dogwatch'), coldBerthPaper)

  it('prints nine blank compartment cards and no deck', () => {
    expect(cards.deck).toEqual(Array.from({ length: 9 }, () => ({ kind: 'compartment' })))
  })

  it('numbers Ship trouble on a d10 and Treason on a d8', () => {
    const by = Object.fromEntries(cards.tables.map((table) => [table.name, table]))

    expect(by['Ship trouble']).toMatchObject({ die: 10, rollAgainAbove: 9 })
    expect(by.Treason).toMatchObject({ die: 8, rollAgainAbove: null })
  })

  it('seals the three Truths on paper rather than opening the tables at the Reveal', () => {
    const sheet = cards.rulesSheet.sections.flatMap((section) => section.lines).join('\n')

    expect(sheet).toMatch(/seal/i)
  })
})
