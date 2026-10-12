import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'

import { ColorModeContext } from './colorModeContext'
import { readStoredColorMode, storeColorMode, type ColorMode } from './colorModeStorage'

const DARK_QUERY = '(prefers-color-scheme: dark)'

interface Override {
  id: symbol
  mode: 'light' | 'dark'
}

function systemPrefersDark(): boolean {
  return typeof window.matchMedia === 'function' && window.matchMedia(DARK_QUERY).matches
}

/**
 * Owns the app's color mode (light, dark or follow the system), remembers it per
 * browser, and mirrors the effective mode onto `<html>` as the `dark` class and
 * `color-scheme`. Overrides (campaign themes) win over the user's choice while mounted.
 */
export function ColorModeProvider({ children }: { children: ReactNode }) {
  const [mode, setModeState] = useState<ColorMode>(readStoredColorMode)
  const [systemDark, setSystemDark] = useState(systemPrefersDark)
  const [overrides, setOverrides] = useState<Override[]>([])

  useEffect(() => {
    if (typeof window.matchMedia !== 'function') {
      return
    }
    const query = window.matchMedia(DARK_QUERY)
    const onChange = (event: MediaQueryListEvent) => {
      setSystemDark(event.matches)
    }
    query.addEventListener('change', onChange)
    return () => {
      query.removeEventListener('change', onChange)
    }
  }, [])

  const override = overrides.at(-1)?.mode
  const effectiveMode = override ?? (mode === 'system' ? (systemDark ? 'dark' : 'light') : mode)

  useEffect(() => {
    const root = document.documentElement
    root.classList.toggle('dark', effectiveMode === 'dark')
    root.style.colorScheme = effectiveMode
  }, [effectiveMode])

  const setMode = useCallback((next: ColorMode) => {
    setModeState(next)
    storeColorMode(next)
  }, [])

  const registerOverride = useCallback((forced: 'light' | 'dark') => {
    const entry: Override = { id: Symbol('color-mode-override'), mode: forced }
    setOverrides((current) => [...current, entry])
    return () => {
      setOverrides((current) => current.filter((item) => item.id !== entry.id))
    }
  }, [])

  const value = useMemo(
    () => ({ mode, setMode, effectiveMode, registerOverride }),
    [mode, setMode, effectiveMode, registerOverride],
  )

  return <ColorModeContext value={value}>{children}</ColorModeContext>
}
