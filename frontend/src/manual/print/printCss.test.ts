import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { DIAGRAM_TOKENS } from '@/shared/flowDiagram'

describe('print.css', () => {
  const css = readFileSync(resolve(import.meta.dirname, 'print.css'), 'utf8')
  // The declarations of every rule whose selector is exactly `.paper`.
  const paper = [...css.matchAll(/(?:^|\n)\.paper\s*\{([^}]*)\}/g)]
    .map((rule) => rule[1])
    .join('\n')

  it.each(DIAGRAM_TOKENS)('defines the theme token --%s a flow diagram paints with', (token) => {
    expect(paper).toMatch(new RegExp(`--${token}\\s*:\\s*[^;\\s]`))
  })
})
