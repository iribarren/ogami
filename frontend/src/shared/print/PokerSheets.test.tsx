import { render, within } from '@testing-library/react'

import { SceneBack, SceneFront, DeckBack, DeckFront, type FaceStyle } from './CardFaces'
import type { Deck, DeckEntryCard, SceneCard } from './cards'
import { pageLayout } from './layout'
import { DuplexSheets, PrintCards } from './PokerSheets'

const sheets = (container: HTMLElement) => [
  ...container.querySelectorAll<HTMLElement>('.print-sheet'),
]

function at<T>(items: readonly T[], index: number): T {
  const item = items[index]
  if (item === undefined) {
    throw new Error(`There is nothing at ${String(index)}.`)
  }
  return item
}

/** The face named by its `data-card`, or a throw. */
function face(name: string): HTMLElement {
  const found = document.querySelector<HTMLElement>(`[data-card="${name}"]`)
  if (found === null) {
    throw new Error(`No face is named "${name}".`)
  }
  return found
}

function gridOf(sheet: Element): HTMLElement {
  const grid = sheet.querySelector<HTMLElement>('.print-grid')
  if (grid === null) {
    throw new Error('The sheet has no grid.')
  }
  return grid
}
const names = (sheet: Element) =>
  [...sheet.querySelectorAll('[data-card]')].map((card) => card.getAttribute('data-card'))

const numbers = Array.from({ length: 11 }, (_, at) => at + 1)

function renderNumbers(paper: 'a4' | 'a5', bleed: boolean, items: readonly number[] = numbers) {
  return render(
    <PrintCards>
      <DuplexSheets
        layout={pageLayout(paper, bleed)}
        items={items}
        front={(n) => <article data-card={`${String(n)}, front`} />}
        back={(n) => <article data-card={`${String(n)}, back`} />}
      />
    </PrintCards>,
  )
}

describe('DuplexSheets', () => {
  it('alternates a sheet of fronts and the sheet of their backs, nine cards to an A4', () => {
    const { container } = renderNumbers('a4', false)

    const all = sheets(container)
    expect(all.map((sheet) => sheet.getAttribute('data-side'))).toEqual([
      'front',
      'back',
      'front',
      'back',
    ])
    expect(names(at(all, 0))).toHaveLength(9)
    expect(names(at(all, 2))).toEqual(['10, front', '11, front'])
  })

  it('mirrors each row of the backs along the long edge, so a back lands behind its front', () => {
    const { container } = renderNumbers('a4', false, numbers.slice(0, 9))

    const [front, back] = [at(sheets(container), 0), at(sheets(container), 1)]
    expect(names(front).map((name) => name?.replace(', front', ''))).toEqual([
      '1',
      '2',
      '3',
      '4',
      '5',
      '6',
      '7',
      '8',
      '9',
    ])
    expect(names(back).map((name) => name?.replace(', back', ''))).toEqual([
      '3',
      '2',
      '1',
      '6',
      '5',
      '4',
      '9',
      '8',
      '7',
    ])
  })

  it('pads the last sheet with gaps, on the back too', () => {
    const { container } = renderNumbers('a5', false, [1])

    const [front, back] = [at(sheets(container), 0), at(sheets(container), 1)]
    expect(front.querySelectorAll('.print-gap')).toHaveLength(3)
    expect(back.querySelectorAll('.print-gap')).toHaveLength(3)
    // Mirrored: the lone back sits in the second column of the first row.
    expect(at([...gridOf(back).children], 1).getAttribute('data-card')).toBe('1, back')
  })

  it('has no sheet for no cards', () => {
    const { container } = renderNumbers('a4', false, [])

    expect(sheets(container)).toEqual([])
  })

  it.each([
    ['a4', false, '210mm', '297mm', 'repeat(3, 63mm)', 'repeat(3, 88mm)'],
    ['a5', false, '148mm', '210mm', 'repeat(2, 63mm)', 'repeat(2, 88mm)'],
    ['a4', true, '210mm', '297mm', 'repeat(2, 69mm)', 'repeat(3, 94mm)'],
    ['a5', true, '148mm', '210mm', 'repeat(2, 69mm)', 'repeat(2, 94mm)'],
  ] as const)(
    'sizes the sheet and grid of %s with bleed %s',
    (paper, bleed, width, height, columns, rows) => {
      const { container } = renderNumbers(paper, bleed, [1])

      const sheet = at(sheets(container), 0)
      const grid = gridOf(sheet)
      expect(sheet.style.width).toBe(width)
      expect(sheet.style.height).toBe(height)
      expect(sheet).toHaveClass(`print-${paper}`)
      expect(grid.style.gridTemplateColumns).toBe(columns)
      expect(grid.style.gridTemplateRows).toBe(rows)
    },
  )

  it('centres the grid the same on the front and the back', () => {
    const { container } = renderNumbers('a4', true, [1])

    const [front, back] = [gridOf(at(sheets(container), 0)), gridOf(at(sheets(container), 1))]
    expect(front.style.left).toBe('36mm')
    expect(front.style.top).toBe('7.5mm')
    expect(back.style.left).toBe(front.style.left)
    expect(back.style.top).toBe(front.style.top)
  })

  it('draws crop marks on a bleed sheet and none on a plain one', () => {
    const bleed = renderNumbers('a4', true, [1])
    const plain = renderNumbers('a4', false, [1])

    // One set on the sheet of fronts and one on the sheet of backs.
    expect(bleed.container.querySelectorAll('.print-marks line')).toHaveLength(
      2 * pageLayout('a4', true).cropMarks.length,
    )
    expect(plain.container.querySelector('.print-marks')).toBeNull()
  })
})

const scene: SceneCard = {
  name: 'Camp',
  purpose: 'Rest.',
  tips: 'Be quick.',
  back: [{ heading: 'Setup', lines: ['Light a fire.'] }],
}
const entry: DeckEntryCard = { kind: 'entry', text: 'A storm.', starts: 'Camp' }
const deck: Deck = { key: 'road', name: 'Road deck', perEntry: false, cards: [entry] }
const plain: FaceStyle = { bleed: false, image: null }
const framed: FaceStyle = { bleed: false, image: '/api/studio/game-systems/k/images/abc' }

describe('card faces', () => {
  it('names each face for the print check and keeps the safe area as padding', () => {
    render(
      <>
        <SceneFront card={scene} face={plain} />
        <SceneBack card={scene} face={plain} />
        <DeckFront deck={deck} card={entry} index={1} face={plain} />
        <DeckBack deck={deck} index={1} face={plain} />
      </>,
    )

    for (const name of ['Camp, front', 'Camp, back', 'Road deck 1, front', 'Road deck 1, back']) {
      expect(face(name)).toBeInTheDocument()
    }
    expect(face('Camp, front').style.padding).toBe('5mm')
  })

  it('prints a Scene Type front with its name, purpose and tips, a back with its sections', () => {
    render(
      <>
        <SceneFront card={scene} face={plain} />
        <SceneBack card={scene} face={plain} />
      </>,
    )

    const front = face('Camp, front')
    expect(within(front).getByRole('heading', { name: 'Camp' })).toBeInTheDocument()
    expect(within(front).getByText('Rest.')).toBeInTheDocument()
    expect(within(front).getByText('Be quick.')).toBeInTheDocument()
    const back = face('Camp, back')
    expect(within(back).getByRole('heading', { name: 'Setup' })).toBeInTheDocument()
    expect(within(back).getByText('Light a fire.')).toBeInTheDocument()
  })

  it('prints a continued card with its name and no tips', () => {
    const cont: SceneCard = { ...scene, name: 'Camp (cont.)', tips: '', continues: 'camp' }
    render(<SceneFront card={cont} face={plain} />)

    const front = face('Camp (cont.), front')
    expect(within(front).getByRole('heading', { name: 'Camp (cont.)' })).toBeInTheDocument()
    expect(front.querySelector('.print-tips')).toBeNull()
  })

  it('prints a deck card with its text, the Scene Type it starts and its range when per entry', () => {
    render(
      <DeckFront
        deck={deck}
        card={{ kind: 'entry', text: 'A storm.', starts: 'Camp', range: '1-3' }}
        index={2}
        face={plain}
      />,
    )

    const card = face('Road deck 2, front')
    expect(within(card).getByText('A storm.')).toBeInTheDocument()
    expect(within(card).getByText('Starts: Camp')).toBeInTheDocument()
    expect(within(card).getByText('1-3')).toBeInTheDocument()
  })

  it('prints no range on a deck card of a deck with a copy per weight', () => {
    render(<DeckFront deck={deck} card={entry} index={1} face={plain} />)

    expect(document.querySelector('.print-range')).toBeNull()
  })

  it('lays the image of a plain face over the trim, shifted 3 mm, and a bleed face as it is', () => {
    render(
      <>
        <SceneBack card={scene} face={framed} />
        <DeckBack deck={deck} index={1} face={{ bleed: true, image: framed.image }} />
      </>,
    )

    const trim = face('Camp, back')
    expect(trim.style.backgroundImage).toContain('/api/studio/game-systems/k/images/abc')
    expect(trim.style.backgroundSize).toBe('69mm 94mm')
    expect(trim.style.backgroundPosition).toBe('-3mm -3mm')
    const bleed = face('Road deck 1, back')
    expect(bleed.style.backgroundPosition).toBe('0mm 0mm')
    expect(bleed.style.padding).toBe('8mm')
  })

  it('has no background image on a face without one', () => {
    render(<SceneFront card={scene} face={plain} />)

    expect(face('Camp, front').style.backgroundImage).toBe('')
  })
})
