import type { ReactNode } from 'react'

import './tableCards.css'

/**
 * The scope of the on-screen card faces (`SceneFront`, `SceneBack`, `DeckFront`, `DeckBack` of
 * `shared/print/CardFaces`). Faces keep their print markup; the `.table-cards` rules size them in
 * rem. Set `--card-width` on this element (or an ancestor) to make the cards bigger or smaller.
 */
export function TableCards({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <div className={className === undefined ? 'table-cards' : `table-cards ${className}`}>
      {children}
    </div>
  )
}
