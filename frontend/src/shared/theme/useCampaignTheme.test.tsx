import { renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'

import { mockSystemColorScheme } from '@/test/matchMedia'

import { ColorModeProvider } from './ColorModeProvider'
import { CAMPAIGN_THEMES, CAMPAIGN_THEME_NAMES } from './themes'
import { useCampaignTheme } from './useCampaignTheme'
import { useColorMode } from './useColorMode'

const root = document.documentElement

function wrapper({ children }: { children: ReactNode }) {
  return <ColorModeProvider>{children}</ColorModeProvider>
}

function renderTheme(theme: string | null | undefined) {
  return renderHook(
    ({ name }: { name: string | null | undefined }) => {
      useCampaignTheme(name)
      return useColorMode()
    },
    { wrapper, initialProps: { name: theme } },
  )
}

beforeEach(() => {
  localStorage.clear()
  root.classList.remove('dark')
  root.removeAttribute('data-theme')
  root.style.colorScheme = ''
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('useCampaignTheme', () => {
  it('lists five curated themes with a fixed mode each', () => {
    expect(CAMPAIGN_THEMES).toEqual({
      parchment: 'light',
      neon: 'dark',
      noir: 'dark',
      eldritch: 'dark',
      starship: 'light',
    })
  })

  it.each(CAMPAIGN_THEME_NAMES)(
    'sets data-theme to %s while mounted and clears it on leave',
    (name) => {
      const { unmount } = renderTheme(name)

      expect(root).toHaveAttribute('data-theme', name)

      unmount()

      expect(root).not.toHaveAttribute('data-theme')
    },
  )

  it.each(CAMPAIGN_THEME_NAMES)('forces the mode of %s over the system preference', (name) => {
    const forced = CAMPAIGN_THEMES[name]
    mockSystemColorScheme(forced === 'light')

    const { result } = renderTheme(name)

    expect(result.current.effectiveMode).toBe(forced)
    expect(root.classList.contains('dark')).toBe(forced === 'dark')
    expect(root.style.colorScheme).toBe(forced)
  })

  it('overrides the mode the user chose, and gives it back on leave', () => {
    mockSystemColorScheme(false)
    localStorage.setItem('ogami.colorMode', 'light')

    const { result, rerender } = renderTheme('noir')

    expect(result.current).toMatchObject({ mode: 'light', effectiveMode: 'dark' })

    rerender({ name: undefined })

    expect(result.current).toMatchObject({ mode: 'light', effectiveMode: 'light' })
    expect(root.classList.contains('dark')).toBe(false)
    expect(root.style.colorScheme).toBe('light')
  })

  it('follows a change of theme and releases when the theme goes away', () => {
    mockSystemColorScheme(false)
    const { result, rerender } = renderTheme('neon')

    expect(root).toHaveAttribute('data-theme', 'neon')
    expect(result.current.effectiveMode).toBe('dark')

    rerender({ name: 'parchment' })

    expect(root).toHaveAttribute('data-theme', 'parchment')
    expect(result.current.effectiveMode).toBe('light')

    rerender({ name: null })

    expect(root).not.toHaveAttribute('data-theme')
    expect(result.current.effectiveMode).toBe('light')
  })

  it.each([null, undefined, 'steampunk', 'Noir', ''])(
    'does nothing for %j: the default theme and the user mode stay',
    (name) => {
      mockSystemColorScheme(true)

      const { result } = renderTheme(name)

      expect(root).not.toHaveAttribute('data-theme')
      expect(result.current).toMatchObject({ mode: 'system', effectiveMode: 'dark' })
    },
  )
})
