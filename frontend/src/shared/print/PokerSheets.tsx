import { Fragment } from 'react'

import { mirrorRows, paginate } from './sheets'
import type { PageLayout } from './layout'

import './printCards.css'

/** The root of every print view of cards: the print CSS is scoped under this class. */
export function PrintCards({ children }: { children: React.ReactNode }) {
  return <div className="print-cards">{children}</div>
}

function CropMarks({ layout }: { layout: PageLayout }) {
  return (
    <svg
      className="print-marks"
      aria-hidden="true"
      width={`${String(layout.pageWidth)}mm`}
      height={`${String(layout.pageHeight)}mm`}
      viewBox={`0 0 ${String(layout.pageWidth)} ${String(layout.pageHeight)}`}
    >
      {layout.cropMarks.map((mark, at) => (
        <line key={at} x1={mark.x1} y1={mark.y1} x2={mark.x2} y2={mark.y2} />
      ))}
    </svg>
  )
}

/** One sheet of a paper: the grid of cards, centred, and the crop marks of a bleed sheet. */
function Sheet({
  layout,
  side,
  cells,
}: {
  layout: PageLayout
  side: 'front' | 'back'
  cells: React.ReactNode[]
}) {
  return (
    <section
      className={[
        'print-sheet',
        `print-${layout.paper}`,
        ...(layout.bleed ? ['print-bleed'] : []),
      ].join(' ')}
      data-side={side}
      style={{ width: `${String(layout.pageWidth)}mm`, height: `${String(layout.pageHeight)}mm` }}
    >
      <div
        className="print-grid"
        style={{
          left: `${String(layout.offsetX)}mm`,
          top: `${String(layout.offsetY)}mm`,
          gridTemplateColumns: `repeat(${String(layout.columns)}, ${String(layout.cardWidth)}mm)`,
          gridTemplateRows: `repeat(${String(layout.rows)}, ${String(layout.cardHeight)}mm)`,
        }}
      >
        {cells}
      </div>
      {layout.bleed && <CropMarks layout={layout} />}
    </section>
  )
}

/**
 * Cards on sheets for double-sided printing: a sheet of fronts, then the sheet of their backs
 * mirrored along the long edge, on the same grid so each back lands behind its front. The last
 * sheet is padded with gaps. Each item draws its own front and back.
 */
export function DuplexSheets<T>({
  layout,
  items,
  front,
  back,
}: {
  layout: PageLayout
  items: readonly T[]
  front: (item: T) => React.ReactNode
  back: (item: T) => React.ReactNode
}) {
  const cells = (sheet: (T | null)[], draw: (item: T) => React.ReactNode) =>
    sheet.map((item, at) =>
      item === null ? (
        <div key={at} className="print-gap" />
      ) : (
        <Fragment key={at}>{draw(item)}</Fragment>
      ),
    )
  return paginate(items, layout.columns, layout.rows).flatMap((sheet, page) => [
    <Sheet
      key={`front-${String(page)}`}
      layout={layout}
      side="front"
      cells={cells(sheet, front)}
    />,
    <Sheet
      key={`back-${String(page)}`}
      layout={layout}
      side="back"
      cells={cells(mirrorRows(sheet, layout.columns), back)}
    />,
  ])
}
