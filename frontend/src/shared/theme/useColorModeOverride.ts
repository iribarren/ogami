import { use, useEffect } from 'react'

import { ColorModeContext } from './colorModeContext'

/**
 * Forces the effective color mode while the calling component is mounted, whatever
 * the user chose. `null` forces nothing. The latest mounted override wins.
 */
export function useColorModeOverride(mode: 'light' | 'dark' | null) {
  const context = use(ColorModeContext)
  if (!context) {
    throw new Error('useColorModeOverride must be used inside a ColorModeProvider')
  }
  const { registerOverride } = context

  useEffect(() => {
    if (mode === null) {
      return
    }
    return registerOverride(mode)
  }, [mode, registerOverride])
}
