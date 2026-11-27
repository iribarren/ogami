import { render } from '@testing-library/react'

import { flowDiagramFromReleaseContent } from '@/shared/flowDiagram'

import { diagramPages } from './diagramPages'
import { FlowDiagramPages, FlowSheetText, TablesSheet } from './FlowSheet'
import type { FlowSheet } from './flowSheet'
import type { OracleTableSheet } from './tablesSheet'
import { miniRelease } from './testRelease'

const sheet: FlowSheet = {
  name: 'The flow',
  description: 'A question?',
  introduction: 'First line.\nSecond line.',
  phases: [
    {
      key: 'road',
      name: 'Road',
      mode: 'Repeats',
      selection: 'Each scene is drawn from The deck.',
      hooks: [{ label: 'World turn', lines: ['One', 'Two'] }],
    },
    {
      key: 'end',
      name: 'End',
      mode: 'Played once',
      description: 'Done.',
      selection: 'Scenes.',
      hooks: [],
    },
  ],
}

describe('the flow sheet', () => {
  it('prints the flow as text on the named page of its paper', () => {
    const { container } = render(<FlowSheetText sheet={sheet} paper="a5" />)

    const page = container.querySelector('section[data-sheet="flow"]')
    expect(page).toHaveClass('print-doc', 'print-a5')
    expect(container.querySelector('h1')).toHaveTextContent('The flow')
    expect([...container.querySelectorAll('h2')].map((h2) => h2.textContent)).toEqual([
      '1. Road (Repeats)',
      '2. End (Played once)',
    ])
    expect([...container.querySelectorAll('h3, li')].map((node) => node.textContent)).toEqual([
      'World turn',
      'One',
      'Two',
    ])
    expect(container.querySelector('.print-introduction')?.textContent).toBe(
      'First line.\nSecond line.',
    )
  })

  it('draws the diagram on a page of its own, sized to the page', () => {
    const pages = diagramPages(flowDiagramFromReleaseContent(miniRelease(), 'mini'), 'a4')
    const { container } = render(<FlowDiagramPages pages={pages} paper="a4" />)

    const page = container.querySelector('section[data-sheet="diagram"]')
    expect(page).toHaveClass('print-doc', 'print-a4', 'print-diagram')
    expect(page?.querySelector('svg')).not.toBeNull()
    const figure = page?.querySelector<HTMLElement>('.print-figure')
    expect(figure?.style.width).toBe(`${String(pages[0]?.width)}mm`)
  })

  describe('the oracle tables', () => {
    const tables: OracleTableSheet[] = [
      {
        key: 'luck',
        name: 'Luck',
        die: 6,
        rollAgainAbove: 5,
        rows: [
          { range: '1-4', text: 'Good', effects: ['Doom -1', 'Next scene: Road'] },
          { range: '5', text: 'Bad', effects: [] },
        ],
      },
    ]

    it('prints each table with its die, a row for each entry and its effects in words', () => {
      const { container } = render(<TablesSheet tables={tables} paper="a4" />)

      expect(container.querySelector('section[data-sheet="tables"]')).toHaveClass(
        'print-doc',
        'print-a4',
      )
      expect(container.querySelector('caption')?.textContent).toBe('Luck: d6, roll again above 5')
      expect([...container.querySelectorAll('tr')].map((row) => row.textContent)).toEqual([
        '1-4Good (Doom -1; Next scene: Road)',
        '5Bad',
      ])
    })

    it('prints nothing for a flow without tables', () => {
      const { container } = render(<TablesSheet tables={[]} paper="a4" />)

      expect(container).toBeEmptyDOMElement()
    })
  })
})
