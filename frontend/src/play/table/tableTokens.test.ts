import { readFileSync } from 'node:fs'
import { resolve as resolvePath } from 'node:path'

// `?raw` imports come back empty while `css: false`, so read the source from disk.
const css = readFileSync(resolvePath(import.meta.dirname, '../../index.css'), 'utf8')

/** The palette blocks, as `contrast.test.ts` finds them: the default, `.dark` and one per Theme. */
const palettes = [...css.matchAll(/^(:root(?:\[data-theme='[a-z-]+'\])?|\.dark)\s*\{([^}]*)\}/gm)]

describe('the table tokens', () => {
  it('are set by the default theme in light and dark, and by each of the five Themes', () => {
    expect(palettes.map(([, name]) => name)).toEqual([
      ':root',
      '.dark',
      ":root[data-theme='parchment']",
      ":root[data-theme='neon']",
      ":root[data-theme='noir']",
      ":root[data-theme='eldritch']",
      ":root[data-theme='starship']",
    ])
    for (const [, name, body] of palettes) {
      for (const token of ['--table-surface', '--table-mat', '--table-card-shadow']) {
        expect(body, `${name ?? ''} sets ${token}`).toMatch(new RegExp(`${token}:\\s*[^;]+;`))
      }
    }
  })
})
