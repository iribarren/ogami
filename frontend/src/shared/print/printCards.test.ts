import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

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
})
