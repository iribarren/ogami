import { readFileSync } from 'node:fs'
import { resolve as resolvePath } from 'node:path'

import { formatHex, parse, wcagContrast } from 'culori'

// Vitest runs from the frontend root; `?raw` imports come back empty while `css: false`.
const css = readFileSync(resolvePath(process.cwd(), 'src/index.css'), 'utf8')

interface Palette {
  name: string
  tokens: Record<string, string>
}

/**
 * Palette blocks are `:root`, `.dark` and `:root[data-theme="x"]` rules. Every palette
 * other than `:root` is layered over it, so it only has to declare what it changes.
 */
const PALETTE_SELECTOR = /^(:root(?:\[data-theme="[a-z-]+"\])?|\.dark)\s*\{([^}]*)\}/gm

function parsePalettes(source: string): Palette[] {
  const palettes: Palette[] = []
  for (const [, name, body] of source.matchAll(PALETTE_SELECTOR)) {
    const tokens: Record<string, string> = {}
    for (const [, token, value] of body.matchAll(/--([a-z0-9-]+)\s*:\s*([^;]+);/g)) {
      tokens[token] = value.trim()
    }
    palettes.push({ name, tokens })
  }
  return palettes
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
  return wcagContrast(colors[0], colors[1])
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
const CONTROL_PAIRS: [string, string][] = [['ring', 'background']]

const palettes = resolve(parsePalettes(css))

describe('palette contrast (WCAG AA)', () => {
  it('finds the light and dark palettes in index.css', () => {
    expect(palettes.map((palette) => palette.name)).toEqual(
      expect.arrayContaining([':root', '.dark']),
    )
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
