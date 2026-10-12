import { readFileSync } from 'node:fs'
import { resolve as resolvePath } from 'node:path'

import { formatHex, parse, wcagContrast } from 'culori'

// `?raw` imports come back empty while `css: false`, so read the source from disk. Vite rewrites
// `new URL(path, import.meta.url)` into an asset URL, hence `import.meta.dirname`.
const css = readFileSync(resolvePath(import.meta.dirname, '../../index.css'), 'utf8')

interface Palette {
  name: string
  tokens: Record<string, string>
}

/**
 * Palette blocks are `:root`, `.dark` and `:root[data-theme="x"]` rules. Every palette
 * other than `:root` is layered over it, so it only has to declare what it changes.
 */
const PALETTE_SELECTOR = /^(:root(?:\[data-theme=(["'])[a-z-]+\2\])?|\.dark)\s*\{([^}]*)\}/gm

function parsePalettes(source: string): Palette[] {
  const palettes: Palette[] = []
  for (const [, name = '', , body = ''] of source.matchAll(PALETTE_SELECTOR)) {
    const tokens: Record<string, string> = {}
    for (const [, token = '', value = ''] of body.matchAll(/--([a-z0-9-]+)\s*:\s*([^;]+);/g)) {
      tokens[token] = value.trim()
    }
    palettes.push({ name, tokens })
  }
  return palettes
}

/**
 * Every `.dark` or `data-theme` selector in the source, ignoring comments and the
 * `@custom-variant` line. Each palette block holds exactly one, so a larger count than
 * the parsed palettes means the parser skipped one.
 */
function countPaletteSelectors(source: string): number {
  const code = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/@custom-variant[^;]*;/g, '')
  return code.match(/\.dark\b|\[data-theme\b/g)?.length ?? 0
}

function resolve(palettes: Palette[]): Palette[] {
  const base = palettes.find((palette) => palette.name === ':root')
  return palettes.map((palette) => ({
    name: palette.name,
    tokens: palette.name === ':root' ? palette.tokens : { ...base?.tokens, ...palette.tokens },
  }))
}

function ratio(palette: Palette, foreground: string, background: string): number {
  const colors = [foreground, background].map((token) => {
    const value = palette.tokens[token]
    if (value === undefined) {
      throw new Error(`${palette.name}: missing token --${token}`)
    }
    const color = parse(value)
    if (color === undefined) {
      throw new Error(`${palette.name}: --${token} is not a color: ${value}`)
    }
    return formatHex(color)
  })
  return wcagContrast(colors[0] ?? '', colors[1] ?? '')
}

// Destructive is used as text on the page, so it has no -foreground token.
const SURFACES = [
  'card',
  'popover',
  'primary',
  'secondary',
  'muted',
  'accent',
  'success',
  'warning',
  'info',
]

/** Text pairs that need 4.5:1 and non-text pairs that need 3:1 (WCAG 2.1 AA). */
const TEXT_PAIRS: [string, string][] = [
  ['foreground', 'background'],
  ...SURFACES.map((surface): [string, string] => [`${surface}-foreground`, surface]),
  ['muted-foreground', 'background'],
  ['muted-foreground', 'muted'],
  ['destructive', 'background'],
]
const CONTROL_PAIRS: [string, string][] = [
  ['ring', 'background'],
  ['input', 'background'],
]

const palettes = resolve(parsePalettes(css))

// Slice 3 adds one `:root[data-theme="<name>"]` entry per curated theme.
const EXPECTED_PALETTES = [':root', '.dark']

describe('palette contrast (WCAG AA)', () => {
  it('finds exactly the expected palettes in index.css', () => {
    expect(palettes.map((palette) => palette.name)).toEqual(EXPECTED_PALETTES)
  })

  it('parses every palette selector in index.css', () => {
    // :root has no selector to count; every other palette has one.
    expect(countPaletteSelectors(css)).toBe(palettes.length - 1)
  })

  it('accepts single and double quoted data-theme selectors', () => {
    const source = ":root[data-theme='a'] { --x: 1; }\n:root[data-theme=\"b\"] { --y: 2; }"
    expect(parsePalettes(source).map((palette) => palette.name)).toEqual([
      ":root[data-theme='a']",
      ':root[data-theme="b"]',
    ])
  })

  describe.each(palettes)('$name', (palette) => {
    it.each(TEXT_PAIRS)('--%s on --%s reaches 4.5:1', (foreground, background) => {
      const actual = ratio(palette, foreground, background)
      expect(
        actual,
        `${palette.name}: --${foreground} on --${background} is ${actual.toFixed(2)}:1, needs 4.5:1`,
      ).toBeGreaterThanOrEqual(4.5)
    })

    it.each(CONTROL_PAIRS)('--%s on --%s reaches 3:1', (foreground, background) => {
      const actual = ratio(palette, foreground, background)
      expect(
        actual,
        `${palette.name}: --${foreground} on --${background} is ${actual.toFixed(2)}:1, needs 3:1`,
      ).toBeGreaterThanOrEqual(3)
    })
  })
})
