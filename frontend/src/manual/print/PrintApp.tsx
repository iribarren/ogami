import { type ReactNode, useEffect } from 'react'

import { CardSheets } from './CardSheets'
import { buildFlowCards } from './flowCards'
import { manualPresets } from '../flowDiagrams'
import { coldBerthPaper } from './cold-berth.paper'
import { PaperBook } from './PaperBook'
import { BOOK_TITLE, paperBook } from './paperEdition'
import type { PaperFlow } from './paperFlow'
import { theLongCarryPaper } from './the-long-carry.paper'

const flows: Record<string, { file: string; paper: PaperFlow; title: string }> = {
  'cards-the-long-carry': {
    file: 'hard-country',
    paper: theLongCarryPaper,
    title: 'The Long Carry',
  },
  'cards-cold-berth': { file: 'dogwatch', paper: coldBerthPaper, title: 'Cold Berth' },
}

/** The document `?doc=` names. An unknown name, or a flow that has drifted from its preset, throws. */
function documentFor(doc: string | null): { title: string; content: ReactNode } {
  if (doc === 'paper') {
    return { title: BOOK_TITLE, content: <PaperBook book={paperBook()} /> }
  }
  const flow = doc !== null && Object.hasOwn(flows, doc) ? flows[doc] : undefined
  if (flow === undefined) {
    throw new Error(
      `Unknown document "${doc ?? ''}". Use paper, ${Object.keys(flows).join(' or ')}.`,
    )
  }
  const raw = manualPresets[flow.file]
  return {
    title: `${flow.title}: card sheets`,
    content: <CardSheets cards={buildFlowCards(raw, flow.paper)} />,
  }
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
