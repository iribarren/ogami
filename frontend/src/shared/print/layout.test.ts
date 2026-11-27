import { frameBackground, isPaper, pageLayout, safeInset } from './layout'

describe('pageLayout', () => {
  it.each([
    ['a4', false, 3, 3, 63, 88],
    ['a5', false, 2, 2, 63, 88],
    ['a4', true, 2, 3, 69, 94],
    ['a5', true, 2, 2, 69, 94],
  ] as const)(
    'puts %s with bleed %s as %s columns by %s rows of %s x %s mm',
    (paper, bleed, columns, rows, width, height) => {
      expect(pageLayout(paper, bleed)).toMatchObject({
        columns,
        rows,
        cardWidth: width,
        cardHeight: height,
      })
    },
  )

  it.each([
    ['a4', false],
    ['a5', false],
    ['a4', true],
    ['a5', true],
  ] as const)('centres the grid of %s with bleed %s on the page', (paper, bleed) => {
    const layout = pageLayout(paper, bleed)
    const gridWidth = layout.columns * layout.cardWidth
    const gridHeight = layout.rows * layout.cardHeight

    expect(layout.offsetX).toBeCloseTo((layout.pageWidth - gridWidth) / 2)
    expect(layout.offsetY).toBeCloseTo((layout.pageHeight - gridHeight) / 2)
    expect(layout.offsetX).toBeGreaterThanOrEqual(0)
    expect(layout.offsetY).toBeGreaterThanOrEqual(0)
  })

  it('has the same page size for every layout of a paper', () => {
    expect(pageLayout('a4', false)).toMatchObject({ pageWidth: 210, pageHeight: 297 })
    expect(pageLayout('a5', true)).toMatchObject({ pageWidth: 148, pageHeight: 210 })
  })

  it('draws no crop marks without bleed, cards butt on shared cut lines', () => {
    expect(pageLayout('a4', false).cropMarks).toEqual([])
  })

  it('draws crop marks at the trim lines of a bleed sheet, in the margin and never over a card', () => {
    const layout = pageLayout('a4', true)
    const left = layout.offsetX
    const top = layout.offsetY
    const right = left + layout.columns * layout.cardWidth
    const bottom = top + layout.rows * layout.cardHeight
    const vertical = layout.cropMarks.filter((mark) => mark.x1 === mark.x2)
    const horizontal = layout.cropMarks.filter((mark) => mark.y1 === mark.y2)

    // Two trim lines (3 mm in from each bleed edge) per column and row, a mark above and below
    // each column line and one at each side of each row line.
    expect(vertical).toHaveLength(layout.columns * 2 * 2)
    expect(horizontal).toHaveLength(layout.rows * 2 * 2)
    expect([...new Set(vertical.map((mark) => mark.x1))].slice(0, 2)).toEqual([
      left + 3,
      left + 69 - 3,
    ])
    expect([...new Set(horizontal.map((mark) => mark.y1))].slice(0, 2)).toEqual([
      top + 3,
      top + 94 - 3,
    ])
    for (const mark of vertical) {
      expect(mark.y2 <= top || mark.y1 >= bottom).toBe(true)
    }
    for (const mark of horizontal) {
      expect(mark.x2 <= left || mark.x1 >= right).toBe(true)
    }
  })

  it.each([
    ['a4', true],
    ['a5', true],
  ] as const)('keeps every crop mark of %s on the page', (paper, bleed) => {
    const layout = pageLayout(paper, bleed)

    for (const mark of layout.cropMarks) {
      for (const x of [mark.x1, mark.x2]) {
        expect(x).toBeGreaterThanOrEqual(0)
        expect(x).toBeLessThanOrEqual(layout.pageWidth)
      }
      for (const y of [mark.y1, mark.y2]) {
        expect(y).toBeGreaterThanOrEqual(0)
        expect(y).toBeLessThanOrEqual(layout.pageHeight)
      }
    }
  })
})

describe('frameBackground', () => {
  it('fills a bleed card with the 69 x 94 mm image', () => {
    expect(frameBackground(true)).toEqual({ size: '69mm 94mm', position: '0mm 0mm' })
  })

  it('crops the image to the trim of a plain card, 3 mm off each side', () => {
    expect(frameBackground(false)).toEqual({ size: '69mm 94mm', position: '-3mm -3mm' })
  })
})

describe('safeInset', () => {
  it('keeps the text 5 mm inside the trim, 8 mm inside a bleed card', () => {
    expect(safeInset(false)).toBe(5)
    expect(safeInset(true)).toBe(8)
  })
})

describe('isPaper', () => {
  it('knows A4 and A5 only', () => {
    expect(isPaper('a4')).toBe(true)
    expect(isPaper('a5')).toBe(true)
    expect(isPaper('A4')).toBe(false)
    expect(isPaper('letter')).toBe(false)
    expect(isPaper(undefined)).toBe(false)
  })
})
