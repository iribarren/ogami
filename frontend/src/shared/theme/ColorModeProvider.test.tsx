import { act, render, renderHook, screen } from '@testing-library/react'
import type { ReactNode } from 'react'

import { mockSystemColorScheme } from '@/test/matchMedia'

import { ColorModeProvider } from './ColorModeProvider'
import { COLOR_MODE_STORAGE_KEY } from './colorModeStorage'
import { useColorMode } from './useColorMode'
import { useColorModeOverride } from './useColorModeOverride'

const root = document.documentElement

function wrapper({ children }: { children: ReactNode }) {
  return <ColorModeProvider>{children}</ColorModeProvider>
}

afterEach(() => {
  vi.restoreAllMocks()
})

beforeEach(() => {
  localStorage.clear()
  root.classList.remove('dark')
  root.style.colorScheme = ''
})

describe('ColorModeProvider', () => {
  it('follows the system by default', () => {
    mockSystemColorScheme(true)

    const { result } = renderHook(() => useColorMode(), { wrapper })

    expect(result.current).toMatchObject({ mode: 'system', effectiveMode: 'dark' })
    expect(root).toHaveClass('dark')
    expect(root.style.colorScheme).toBe('dark')
  })

  it('applies the stored mode over the system preference', () => {
    mockSystemColorScheme(true)
    localStorage.setItem(COLOR_MODE_STORAGE_KEY, 'light')

    const { result } = renderHook(() => useColorMode(), { wrapper })

    expect(result.current).toMatchObject({ mode: 'light', effectiveMode: 'light' })
    expect(root).not.toHaveClass('dark')
    expect(root.style.colorScheme).toBe('light')
  })

  it('falls back to system when the stored value is not a mode', () => {
    mockSystemColorScheme(false)
    localStorage.setItem(COLOR_MODE_STORAGE_KEY, 'purple')

    const { result } = renderHook(() => useColorMode(), { wrapper })

    expect(result.current).toMatchObject({ mode: 'system', effectiveMode: 'light' })
  })

  it('follows live changes of the system preference while in system mode', () => {
    const system = mockSystemColorScheme(false)
    const { result } = renderHook(() => useColorMode(), { wrapper })

    act(() => {
      system.setDark(true)
    })

    expect(result.current.effectiveMode).toBe('dark')
    expect(root).toHaveClass('dark')
  })

  it('ignores system changes once a mode is chosen', () => {
    const system = mockSystemColorScheme(false)
    const { result } = renderHook(() => useColorMode(), { wrapper })
    act(() => {
      result.current.setMode('light')
    })

    act(() => {
      system.setDark(true)
    })

    expect(result.current.effectiveMode).toBe('light')
    expect(root).not.toHaveClass('dark')
  })

  it('persists the chosen mode', () => {
    mockSystemColorScheme(false)
    const { result } = renderHook(() => useColorMode(), { wrapper })

    act(() => {
      result.current.setMode('dark')
    })

    expect(localStorage.getItem(COLOR_MODE_STORAGE_KEY)).toBe('dark')
    expect(result.current).toMatchObject({ mode: 'dark', effectiveMode: 'dark' })
    expect(root).toHaveClass('dark')
  })

  it('stops listening to the system on unmount', () => {
    const system = mockSystemColorScheme(false)
    const { unmount } = renderHook(() => useColorMode(), { wrapper })

    unmount()

    expect(system.listenerCount()).toBe(0)
  })

  it('works when there is no matchMedia', () => {
    Object.defineProperty(window, 'matchMedia', {
      value: undefined,
      writable: true,
      configurable: true,
    })

    const { result } = renderHook(() => useColorMode(), { wrapper })

    expect(result.current).toMatchObject({ mode: 'system', effectiveMode: 'light' })
  })

  it('works when storage throws', () => {
    mockSystemColorScheme(false)
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    const { result } = renderHook(() => useColorMode(), { wrapper })

    act(() => {
      result.current.setMode('dark')
    })

    expect(result.current).toMatchObject({ mode: 'dark', effectiveMode: 'dark' })
  })

  it('lets a mounted override win over the user mode and releases it on unmount', () => {
    mockSystemColorScheme(false)
    localStorage.setItem(COLOR_MODE_STORAGE_KEY, 'light')

    function Probe() {
      const { mode, effectiveMode } = useColorMode()
      return (
        <p>
          {mode}/{effectiveMode}
        </p>
      )
    }
    function Forced() {
      useColorModeOverride('dark')
      return null
    }
    function Harness({ forced }: { forced: boolean }) {
      return (
        <ColorModeProvider>
          <Probe />
          {forced && <Forced />}
        </ColorModeProvider>
      )
    }

    const { rerender } = render(<Harness forced />)
    expect(screen.getByText('light/dark')).toBeInTheDocument()
    expect(root).toHaveClass('dark')
    expect(localStorage.getItem(COLOR_MODE_STORAGE_KEY)).toBe('light')

    rerender(<Harness forced={false} />)
    expect(screen.getByText('light/light')).toBeInTheDocument()
    expect(root).not.toHaveClass('dark')
  })

  it('ignores a null override', () => {
    mockSystemColorScheme(false)
    const { result } = renderHook(
      () => {
        useColorModeOverride(null)
        return useColorMode()
      },
      { wrapper },
    )

    expect(result.current.effectiveMode).toBe('light')
  })
})
