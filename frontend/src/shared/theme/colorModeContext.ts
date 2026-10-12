import { createContext } from 'react'

import type { ColorMode } from './colorModeStorage'

export interface ColorModeContextValue {
  /** The user's choice. */
  mode: ColorMode
  setMode: (mode: ColorMode) => void
  /** What is actually shown: an active override, else the mode, else the system preference. */
  effectiveMode: 'light' | 'dark'
  /** Forces the effective mode until the returned function is called. */
  registerOverride: (mode: 'light' | 'dark') => () => void
}

export const ColorModeContext = createContext<ColorModeContextValue | null>(null)
