import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { DIAGRAM_TOKENS } from '@/shared/flowDiagram'

import { DOC_MARGIN } from './layout'

const css = readFileSync(resolve(import.meta.dirname, 'printCards.css'), 'utf8').replace(
  /\/\*[\s\S]*?\*\//g,
  '',
)
// Everything before an opening brace: a selector list, or an at-rule header.
const headers = [...css.matchAll(/([^{}]+)\{/g)].map((match) => (match[1] ?? '').trim())

describe('printCards.css', () => {
  it('has no unnamed @page rule, which would size every page of the app', () => {
    const pages = headers.filter((header) => header.startsWith('@page'))

    expect(pages).toEqual(['@page print-a4', '@page print-a5'])
  })

  it('sizes the named pages to A4 and A5 with no margin', () => {
    expect(css).toMatch(/@page print-a4\s*\{\s*size: A4;\s*margin: 0;\s*\}/)
    expect(css).toMatch(/@page print-a5\s*\{\s*size: A5;\s*margin: 0;\s*\}/)
  })

  it('scopes every rule under .print-cards', () => {
    const selectors = headers
      .filter((header) => !header.startsWith('@'))
      .flatMap((header) => header.split(','))
      .map((selector) => selector.trim())

    expect(selectors.length).toBeGreaterThan(10)
    expect(selectors.filter((selector) => !selector.includes('.print-cards'))).toEqual([])
  })

  it('hides the app shell only while a print view is on the page', () => {
    expect(css).toMatch(/body:has\(\.print-cards\) \[data-app-chrome\]\s*\{\s*display: none;/)
    expect(headers.filter((header) => header.startsWith('@media'))).toEqual(['@media print'])
  })

  it('defines every theme token a flow diagram paints with, black on white, for the pages of text', () => {
    const doc = /\.print-cards \.print-doc\s*\{([^}]*)\}/.exec(css)?.[1] ?? ''

    for (const token of DIAGRAM_TOKENS) {
      expect(doc).toMatch(new RegExp(`--${token}\\s*:\\s*#[0-9a-f]{3,6};`))
    }
  })

  it('pads a page of text by the document margin, on every page it spans, and gives it the paper width', () => {
    expect(css).toMatch(new RegExp(`padding: ${String(DOC_MARGIN)}mm;`))
    expect(css).toMatch(/box-decoration-break: clone;/)
    expect(css).toMatch(/\.print-doc\.print-a4\s*\{\s*width: 210mm;/)
    expect(css).toMatch(/\.print-doc\.print-a5\s*\{\s*width: 148mm;/)
  })

  it('keeps a phase, a hook and a table row whole, and hides the text alternative of the diagram', () => {
    expect(css).toMatch(
      /\.print-phase,\s*\.print-cards \.print-doc \.print-hook\s*\{\s*break-inside: avoid;/,
    )
    expect(css).toMatch(/\.print-doc tr\s*\{\s*break-inside: avoid;/)
    expect(css).toMatch(/\.print-diagram figure details\s*\{\s*display: none;/)
  })
})
