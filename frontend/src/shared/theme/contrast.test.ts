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
  // Form and group labels sit on cards and on the page.
  ['label-color', 'card'],
  ['label-color', 'background'],
]
const CONTROL_PAIRS: [string, string][] = [
  ['ring', 'background'],
  ['input', 'background'],
]

/** The tile background is translucent in some themes: composite it over `backdrop` first. */
function flatten(palette: Palette, token: string, backdrop: string): string {
  const color = rgb(parse(palette.tokens[token] ?? ''))
  const under = rgb(parse(palette.tokens[backdrop] ?? ''))
  const alpha = color.alpha ?? 1
  const mix = (a: number, b: number) => a * alpha + b * (1 - alpha)
  return formatHex({
    mode: 'rgb',
    r: mix(color.r, under.r),
    g: mix(color.g, under.g),
    b: mix(color.b, under.b),
  })
}

function tileRatio(palette: Palette, foreground: string, background: string | null): number {
  const color = formatHex(parse(palette.tokens[foreground] ?? ''))
  const backdrop =
    background === null ? flatten(palette, 'card', 'card') : flatten(palette, background, 'card')
  return wcagContrast(color, backdrop)
}

const palettes = resolve(parsePalettes(css))

// Prettier writes the attribute selector with single quotes.
const themeSelector = (name: string) => `:root[data-theme='${name}']`
const EXPECTED_PALETTES = [
  ':root',
  '.dark',
  ...CAMPAIGN_THEME_NAMES.map((name) => themeSelector(name)),
]

// Layout tokens and the radius belong to the app, not to a palette.
const NOT_PALETTE_TOKENS = ['radius', 'header-height', 'play-sidebar-width']

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
        [...DEPTH_TOKENS, ...TYPE_TOKENS, ...TILE_TOKENS].filter(
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

    it('--icon reaches 3:1 on --card', () => {
      const actual = tileRatio(palette, 'icon', null)
      expect(actual, `${palette.name}: icon is ${actual.toFixed(2)}:1`).toBeGreaterThanOrEqual(3)
    })
  })
})
