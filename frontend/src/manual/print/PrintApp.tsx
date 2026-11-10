import { type ReactNode, useEffect } from 'react'

import { PaperBook } from './PaperBook'
import { BOOK_TITLE, paperBook } from './paperEdition'

/** The document `?doc=` names. An unknown name throws. */
function documentFor(doc: string | null): { title: string; content: ReactNode } {
  if (doc === 'paper') {
    return { title: BOOK_TITLE, content: <PaperBook book={paperBook()} /> }
  }
  throw new Error(`Unknown document "${doc ?? ''}". Use paper.`)
}

/**
 * Renders one printable document and marks the page `ready` (or `error`, with the message shown),
 * which the print spec waits for. A document that cannot be built shows its error: it is never an
 * empty page that prints.
 */
export function PrintApp({ doc }: { doc: string | null }) {
  let built: { title: string; content: ReactNode } | undefined
  let error = ''
  try {
    built = documentFor(doc)
  } catch (caught) {
    error = caught instanceof Error ? caught.message : 'The document could not be built.'
  }
  useEffect(() => {
    if (built !== undefined) {
      document.title = built.title
    }
    document.body.dataset.print = built === undefined ? 'error' : 'ready'
  })
  return built === undefined ? <p role="alert">{error}</p> : built.content
}
