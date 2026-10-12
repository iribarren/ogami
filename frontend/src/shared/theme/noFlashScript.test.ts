import { readFileSync } from 'node:fs'
import { resolve as resolvePath } from 'node:path'
import { runInThisContext } from 'node:vm'

import { COLOR_MODE_STORAGE_KEY, type ColorMode } from './colorModeStorage'

const html = readFileSync(resolvePath(import.meta.dirname, '../../../index.html'), 'utf8')

/** The inline (no `src`) script that applies the color mode before first paint. */
function inlineScript(): string {
  for (const [, attributes = '', code = ''] of html.matchAll(
    /<script([^>]*)>([\s\S]*?)<\/script>/g,
  )) {
    if (!/\bsrc=/.test(attributes)) {
      return code
    }
  }
  throw new Error('index.html has no inline script')
}

function stubSystem(dark: boolean) {
  vi.stubGlobal('matchMedia', (query: string) => ({
    matches: query === '(prefers-color-scheme: dark)' && dark,
  }))
}

function runScript() {
  // Runs against the jsdom globals, like the browser runs it against the real ones.
  runInThisContext(inlineScript())
}

/** What the provider writes for a stored `mode` when the system prefers dark or not. */
const expected: [ColorMode | 'unknown', boolean, 'light' | 'dark'][] = [
  ['light', true, 'light'],
  ['light', false, 'light'],
  ['dark', true, 'dark'],
  ['dark', false, 'dark'],
  ['system', true, 'dark'],
  ['system', false, 'light'],
  // An unknown stored value behaves like `system`.
  ['unknown', true, 'dark'],
  ['unknown', false, 'light'],
]

describe('the no-flash script in index.html', () => {
  beforeEach(() => {
    localStorage.clear()
    document.documentElement.classList.remove('dark')
    document.documentElement.style.colorScheme = ''
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it.each(expected)(
    'stored %s with system dark=%s applies %s like the provider',
    (stored, systemDark, effective) => {
      localStorage.setItem(COLOR_MODE_STORAGE_KEY, stored === 'unknown' ? 'sepia' : stored)
      stubSystem(systemDark)

      runScript()

      expect(document.documentElement.classList.contains('dark')).toBe(effective === 'dark')
      expect(document.documentElement.style.colorScheme).toBe(effective)
    },
  )

  it('follows the system when nothing is stored', () => {
    stubSystem(true)

    runScript()

    expect(document.documentElement).toHaveClass('dark')
    expect(document.documentElement.style.colorScheme).toBe('dark')
  })
})
