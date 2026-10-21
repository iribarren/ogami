import { readFileSync } from 'node:fs'
import { resolve as resolvePath } from 'node:path'

import { formatHex, parse, rgb, wcagContrast } from 'culori'

import { CAMPAIGN_THEME_NAMES, CAMPAIGN_THEMES } from './themes'

// `?raw` imports come back empty while `css: false`, so read the source from disk. Vite rewrites
// `new URL(path, import.meta.url)` into an asset URL, hence `import.meta.dirname`.
const css = readFileSync(resolvePath(import.meta.dirname, '../../index.css'), 'utf8')

interface Palette {
  name: string
  tokens: Record<string, string>
  /** The `color-scheme` the block declares, if any. */
  colorScheme?: string
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
    const colorScheme = /(?:^|[\s;])color-scheme\s*:\s*([^;]+);/.exec(body)?.[1]?.trim()
    palettes.push({ name, tokens, ...(colorScheme === undefined ? {} : { colorScheme }) })
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
    ...palette,
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
  // Text and icons of a warning alert, which sits on the page and on cards.
  ['warning-text', 'background'],
  ['warning-text', 'card'],
  // Form and group labels sit on cards and on the page.
  ['label-color', 'card'],
  ['label-color', 'background'],
]
const CONTROL_PAIRS: [string, string][] = [
  ['ring', 'background'],
  ['input', 'background'],
]

function rgbOf(palette: Palette, token: string) {
  const color = rgb(parse(palette.tokens[token] ?? ''))
  if (color === undefined) {
    throw new Error(`${palette.name}: --${token} is not a color`)
  }
  return color
}

/** The tile background is translucent in some themes: composite it over the card first. */
function overCard(palette: Palette, token: string): string {
  const color = rgbOf(palette, token)
  const under = rgbOf(palette, 'card')
  const alpha = color.alpha ?? 1
  const mix = (a: number, b: number) => a * alpha + b * (1 - alpha)
  return formatHex({
    mode: 'rgb',
    r: mix(color.r, under.r),
    g: mix(color.g, under.g),
    b: mix(color.b, under.b),
  })
}

function tileRatio(palette: Palette, foreground: string, background: string): number {
  return wcagContrast(formatHex(rgbOf(palette, foreground)), overCard(palette, background))
}

const palettes = resolve(parsePalettes(css))

// Prettier writes the attribute selector with single quotes.
const themeSelector = (name: string) => `:root[data-theme='${name}']`
const EXPECTED_PALETTES = [
  ':root',
  '.dark',
  ...CAMPAIGN_THEME_NAMES.map((name) => themeSelector(name)),
]

// Layout tokens belong to the app, not to a palette; the radius is declared per theme.
const NOT_PALETTE_TOKENS = ['header-height', 'play-sidebar-width']

// Non-color styling of a theme is declared per palette, so theme-neutral utilities can read it.
const DEPTH_TOKENS = [
  'well',
  'well-shadow',
  'card-shadow',
  'button-shadow',
  'readout-shadow',
  'texture',
  'texture-size',
]
const TILE_TOKENS = ['tile-radius', 'tile-bg', 'tile-border', 'tile-fg', 'icon']
// Frame, title mark and divider ornament: decorations whose shapes each theme chooses.
const EMBELLISHMENT_TOKENS = [
  'radius',
  'panel-border',
  'frame-bracket',
  'frame-bracket-width',
  'frame-corner',
  'frame-bar',
  'frame-glow',
  'mark-display',
  'mark-initial-display',
  'mark-rotate',
  'mark-radius',
  'mark-ink',
  'mark-fg',
  'mark-glyph',
  'mark-mask',
  'mark-shadow',
  'mark-glow',
  'ornament-height',
  'ornament-image',
  'ornament-size',
  'ornament-position',
  'ornament-glow',
  'dropcap-font',
  'dropcap-size',
  'dropcap-line',
  'dropcap-float',
  'dropcap-padding',
  'dropcap-color',
  'dropcap-shadow',
  'pip-height',
  'pip-radius',
]
const TYPE_TOKENS = [
  'label-font',
  'label-size',
  'label-weight',
  'label-case',
  'label-tracking',
  'label-style',
  'label-caps',
  'label-color',
  'text-page-title--letter-spacing',
  'page-title-case',
  'page-title-shadow',
  'text-panel-title',
  'text-panel-title--font-weight',
  'text-panel-title--letter-spacing',
  'panel-title-case',
  'prose-font',
  'prose-size',
  'button-font',
  'button-size',
  'button-case',
  'button-tracking',
]

describe('palette contrast (WCAG AA)', () => {
  it('finds exactly the expected palettes in index.css', () => {
    expect(palettes.map((palette) => palette.name)).toEqual(EXPECTED_PALETTES)
  })

  it('parses every palette selector in index.css', () => {
    // :root has no selector to count; every other palette has one.
    expect(countPaletteSelectors(css)).toBe(palettes.length - 1)
  })

  it('accepts single and double quoted data-theme selectors', () => {
    const source = ':root[data-theme=\'a\'] { --x: 1; }\n:root[data-theme="b"] { --y: 2; }'
    expect(parsePalettes(source).map((palette) => palette.name)).toEqual([
      ":root[data-theme='a']",
      ':root[data-theme="b"]',
    ])
  })

  describe.each(CAMPAIGN_THEME_NAMES)('theme %s', (name) => {
    const own = parsePalettes(css).find((palette) => palette.name === themeSelector(name))
    const base = parsePalettes(css).find((palette) => palette.name === ':root')

    it('declares a full palette, not a partial override of the default one', () => {
      const missing = Object.keys(base?.tokens ?? {}).filter(
        (token) => !NOT_PALETTE_TOKENS.includes(token) && own?.tokens[token] === undefined,
      )
      expect(missing).toEqual([])
    })

    it('sets its heading font', () => {
      expect(own?.tokens['font-heading']).toBeDefined()
    })

    it(`declares color-scheme ${CAMPAIGN_THEMES[name]}, the mode the theme forces`, () => {
      expect(own?.colorScheme).toBe(CAMPAIGN_THEMES[name])
    })
  })

  describe.each(parsePalettes(css))('$name', (palette) => {
    it('declares every depth and type token itself', () => {
      expect(
        [...DEPTH_TOKENS, ...TYPE_TOKENS, ...TILE_TOKENS, ...EMBELLISHMENT_TOKENS].filter(
          (token) => palette.tokens[token] === undefined,
        ),
      ).toEqual([])
    })
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

    // Icons are non-text: 3:1 against what they sit on (the tile over the card, or the card).
    it('--tile-fg reaches 3:1 on --tile-bg over --card', () => {
      const actual = tileRatio(palette, 'tile-fg', 'tile-bg')
      expect(actual, `${palette.name}: tile icon is ${actual.toFixed(2)}:1`).toBeGreaterThanOrEqual(
        3,
      )
    })

    // The title mark is non-text: its ink on the card, and its glyph or initial on its ink.
    it('--mark-ink reaches 3:1 on --card', () => {
      const actual = ratio(palette, 'mark-ink', 'card')
      expect(actual, `${palette.name}: mark is ${actual.toFixed(2)}:1`).toBeGreaterThanOrEqual(3)
    })

    it('--mark-fg reaches 3:1 on --mark-ink', () => {
      const actual = ratio(palette, 'mark-fg', 'mark-ink')
      expect(
        actual,
        `${palette.name}: mark glyph is ${actual.toFixed(2)}:1`,
      ).toBeGreaterThanOrEqual(3)
    })

    // The drop cap is large text, so 3:1 on the card.
    it('--dropcap-color reaches 3:1 on --card', () => {
      const actual = ratio(palette, 'dropcap-color', 'card')
      expect(actual, `${palette.name}: drop cap is ${actual.toFixed(2)}:1`).toBeGreaterThanOrEqual(
        3,
      )
    })

    it('--icon reaches 3:1 on --card', () => {
      const actual = tileRatio(palette, 'icon', 'card')
      expect(actual, `${palette.name}: icon is ${actual.toFixed(2)}:1`).toBeGreaterThanOrEqual(3)
    })
  })
})
