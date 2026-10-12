import { use } from 'react'

import { ColorModeContext } from './colorModeContext'

/** The color mode, its setter and the effective light/dark mode. Needs a `ColorModeProvider`. */
export function useColorMode() {
  const context = use(ColorModeContext)
  if (!context) {
    throw new Error('useColorMode must be used inside a ColorModeProvider')
  }
  const { mode, setMode, effectiveMode } = context
  return { mode, setMode, effectiveMode }
}
