import { render } from '@testing-library/react'

import { CardSheets } from './CardSheets'
import type { FlowCards, SceneCard } from './flowCards'

const scene = (name: string): SceneCard => ({
  name,
  purpose: 'A purpose.',
  tips: 'A tip.',
  back: [{ heading: 'Play', lines: [`Do ${name}.`] }],
})

const cards = (names: string[], compartments = 0): FlowCards => ({
  sceneCards: names.map(scene),
  deck: Array.from({ length: compartments }, () => ({ kind: 'compartment' as const })),
  deckBack: 'The ship',
  rulesSheet: { title: 'Flow', question: 'A question?', sections: [], credit: 'Credit.' },
  tables: [],
})

/** What each cell of a sheet holds: the card's name, or `gap`. */
const cells = (sheet: Element | undefined) =>
  Array.from(sheet?.children ?? []).map((cell) => cell.getAttribute('data-card') ?? 'gap')

const sheetsOf = (container: HTMLElement, selector: string) =>
  Array.from(container.querySelectorAll(selector))

describe('CardSheets', () => {
  it('prints each sheet of Scene Type cards, then its back with every row mirrored', () => {
    const { container } = render(<CardSheets cards={cards(['A', 'B', 'C', 'D'])} />)
    const [front, back] = sheetsOf(container, '.sheet.a6')

    expect(cells(front)).toEqual(['A, front', 'B, front', 'C, front', 'D, front'])
    expect(cells(back)).toEqual(['B, back', 'A, back', 'D, back', 'C, back'])
  })

  it('keeps the gaps of a partly filled last sheet behind their cards', () => {
    const { container } = render(<CardSheets cards={cards(['A', 'B', 'C', 'D', 'E', 'F'])} />)
    const sheets = sheetsOf(container, '.sheet.a6')

    expect(sheets).toHaveLength(4)
    expect(cells(sheets[2])).toEqual(['E, front', 'F, front', 'gap', 'gap'])
    expect(cells(sheets[3])).toEqual(['F, back', 'E, back', 'gap', 'gap'])
  })

  it('mirrors the back of a single card to the other side of its row', () => {
    const { container } = render(<CardSheets cards={cards(['A', 'B', 'C', 'D', 'E'])} />)
    const sheets = sheetsOf(container, '.sheet.a6')

    expect(cells(sheets[2])).toEqual(['E, front', 'gap', 'gap', 'gap'])
    expect(cells(sheets[3])).toEqual(['gap', 'E, back', 'gap', 'gap'])
  })

  it('mirrors the deck in rows of three, with its gaps', () => {
    const { container } = render(<CardSheets cards={cards(['A'], 10)} />)
    const sheets = sheetsOf(container, '.sheet.small')

    expect(sheets).toHaveLength(4)
    expect(cells(sheets[2])).toHaveLength(9)
    expect(cells(sheets[2]).slice(1)).toEqual(Array.from({ length: 8 }, () => 'gap'))
    expect(cells(sheets[3]).map((cell) => cell === 'gap')).toEqual([
      true,
      true,
      false,
      ...Array.from({ length: 6 }, () => true),
    ])
  })
})
