import type { ReactNode } from 'react'

import { cn } from '@/shared/lib/utils'

/** The places at the table's edge. A slot nobody fills yet says what it will hold. */
const TABLE_SLOTS = [
  { id: 'deck', label: 'Deck', place: '@3xl:col-start-1 @3xl:row-start-1' },
  { id: 'dice', label: 'Dice tray', place: '@3xl:col-start-1 @3xl:row-start-2' },
  { id: 'notebook', label: 'Notebook', place: '@3xl:col-start-3 @3xl:row-start-1' },
  { id: 'oracle', label: 'Oracle book', place: '@3xl:col-start-3 @3xl:row-start-2' },
  { id: 'map', label: 'Flow map', place: '@3xl:col-start-3 @3xl:row-start-3' },
] as const

function Slot({ label, place, children }: { label: string; place: string; children?: ReactNode }) {
  return (
    <section
      aria-label={label}
      data-slot={label}
      className={cn(
        'flex min-h-14 items-center justify-center rounded-xl border border-dashed bg-card/60 p-2 text-center text-xs text-muted-foreground',
        '@3xl:min-h-28 @3xl:text-sm',
        place,
      )}
    >
      {children ?? label}
    </section>
  )
}

/**
 * The table's layout, by the width of its own container (not the screen's): from `3xl` up the mat
 * lies in the middle with the deck and the dice tray on one side and the notebook, the oracle book
 * and the flow map on the other; narrower, the mat is a strip and the same slots form a dock at the
 * bottom. The slots are one set of elements, only placed differently.
 */
export function TableLayout({
  mat,
  slots = {},
}: {
  mat: ReactNode
  slots?: Partial<Record<(typeof TABLE_SLOTS)[number]['id'], ReactNode>>
}) {
  return (
    <div className="@container">
      <div className="grid gap-3 @3xl:grid-cols-[11rem_minmax(0,1fr)_11rem] @3xl:grid-rows-[auto_auto_1fr] @3xl:items-stretch">
        <div className="min-w-0 @3xl:col-start-2 @3xl:row-span-3 @3xl:row-start-1">{mat}</div>
        <div
          role="group"
          aria-label="Table tools"
          className="sticky bottom-0 z-10 -mx-1 grid grid-cols-5 gap-1 bg-background/90 p-1 backdrop-blur @3xl:contents"
        >
          {TABLE_SLOTS.map((slot) => (
            <Slot key={slot.id} label={slot.label} place={slot.place}>
              {slots[slot.id]}
            </Slot>
          ))}
        </div>
      </div>
    </div>
  )
}
