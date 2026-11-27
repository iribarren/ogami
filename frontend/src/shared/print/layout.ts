/**
 * Where poker cards go on a sheet, as pure numbers in millimetres: how many fit a paper, where
 * the grid sits, where the crop marks of a bleed sheet are, and how a frame image is placed on
 * a card. The components only draw what this says (no DOM, so the geometry is unit-tested).
 */

export const PAPERS = ['a4', 'a5'] as const
export type Paper = (typeof PAPERS)[number]

export const isPaper = (value: unknown): value is Paper =>
  typeof value === 'string' && (PAPERS as readonly string[]).includes(value)

const PAGES = {
  a4: { width: 210, height: 297 },
  a5: { width: 148, height: 210 },
} as const satisfies Record<Paper, { width: number; height: number }>

/** The margin of a page of text or of a diagram, in millimetres (the named pages themselves have none). */
export const DOC_MARGIN = 14

/** What a page of text or a diagram can use: the paper without the margin on each side. */
export function printableArea(paper: Paper): { width: number; height: number } {
  const page = PAGES[paper]
  return { width: page.width - 2 * DOC_MARGIN, height: page.height - 2 * DOC_MARGIN }
}

/** A poker card: 63 × 88 mm, the trim. */
const POKER = { width: 63, height: 88 } as const
/** The bleed printed beyond the trim on each side. */
const BLEED = 3
/** The text keeps this far inside the trim (ADR 0053). */
const SAFE = 5

/** Cards to a sheet: butted on shared cut lines, and fewer with bleed. */
const GRIDS = {
  plain: { a4: { columns: 3, rows: 3 }, a5: { columns: 2, rows: 2 } },
  bleed: { a4: { columns: 2, rows: 3 }, a5: { columns: 2, rows: 2 } },
} as const

/** The gap between a crop mark and the bleed edge, and the length of a mark. */
const MARK_GAP = 1
const MARK_LENGTH = 3

export interface CropMark {
  x1: number
  y1: number
  x2: number
  y2: number
}

export interface PageLayout {
  paper: Paper
  bleed: boolean
  pageWidth: number
  pageHeight: number
  columns: number
  rows: number
  /** The size of one card on the sheet: the trim, or the trim plus the bleed. */
  cardWidth: number
  cardHeight: number
  /** Where the grid starts: it is centred, the same on a front and on its back. */
  offsetX: number
  offsetY: number
  /** Empty without bleed: the cards share their cut lines. */
  cropMarks: CropMark[]
}

/** Marks in the margin at the trim lines: 3 mm in from each edge of each column and row. */
function cropMarks(layout: Omit<PageLayout, 'cropMarks'>): CropMark[] {
  const { columns, rows, cardWidth, cardHeight, offsetX, offsetY } = layout
  const right = offsetX + columns * cardWidth
  const bottom = offsetY + rows * cardHeight
  const trims = (count: number, size: number, offset: number) =>
    Array.from({ length: count }, (_, at) => [
      offset + at * size + BLEED,
      offset + (at + 1) * size - BLEED,
    ]).flat()
  const marks: CropMark[] = []
  for (const x of trims(columns, cardWidth, offsetX)) {
    marks.push(
      { x1: x, y1: offsetY - MARK_GAP - MARK_LENGTH, x2: x, y2: offsetY - MARK_GAP },
      { x1: x, y1: bottom + MARK_GAP, x2: x, y2: bottom + MARK_GAP + MARK_LENGTH },
    )
  }
  for (const y of trims(rows, cardHeight, offsetY)) {
    marks.push(
      { x1: offsetX - MARK_GAP - MARK_LENGTH, y1: y, x2: offsetX - MARK_GAP, y2: y },
      { x1: right + MARK_GAP, y1: y, x2: right + MARK_GAP + MARK_LENGTH, y2: y },
    )
  }
  return marks
}

export function pageLayout(paper: Paper, bleed: boolean): PageLayout {
  const page = PAGES[paper]
  const { columns, rows } = GRIDS[bleed ? 'bleed' : 'plain'][paper]
  const cardWidth = POKER.width + (bleed ? 2 * BLEED : 0)
  const cardHeight = POKER.height + (bleed ? 2 * BLEED : 0)
  const layout = {
    paper,
    bleed,
    pageWidth: page.width,
    pageHeight: page.height,
    columns,
    rows,
    cardWidth,
    cardHeight,
    offsetX: (page.width - columns * cardWidth) / 2,
    offsetY: (page.height - rows * cardHeight) / 2,
  }
  return { ...layout, cropMarks: bleed ? cropMarks(layout) : [] }
}

/**
 * How the frame (or back) image sits on a face. It is 69 × 94 mm, the trim plus the bleed: a bleed
 * card shows all of it, a plain card is the trim only, so the image is shifted 3 mm up and left
 * and the bleed falls outside the card.
 */
export function frameBackground(bleed: boolean): { size: string; position: string } {
  const shift = bleed ? 0 : -BLEED
  return {
    size: `${String(POKER.width + 2 * BLEED)}mm ${String(POKER.height + 2 * BLEED)}mm`,
    position: `${String(shift)}mm ${String(shift)}mm`,
  }
}

/** The padding of a face: the safe area from the trim, and the bleed on top of it when there is one. */
export const safeInset = (bleed: boolean): number => SAFE + (bleed ? BLEED : 0)
