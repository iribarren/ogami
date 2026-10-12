import { useEffect } from 'react'

import { CAMPAIGN_THEMES, isCampaignTheme } from './themes'
import { useColorModeOverride } from './useColorModeOverride'

/**
 * Applies a Campaign's curated Theme while the calling component is mounted: `data-theme` on
 * `<html>` (so portals and the app header are themed too) and the theme's fixed color mode, which
 * wins over the user's choice. A missing or unknown name changes nothing: the default theme stays.
 */
export function useCampaignTheme(theme: string | null | undefined) {
  const known = isCampaignTheme(theme) ? theme : null
  useColorModeOverride(known === null ? null : CAMPAIGN_THEMES[known])

  useEffect(() => {
    if (known === null) {
      return
    }
    const root = document.documentElement
    root.setAttribute('data-theme', known)
    return () => {
      root.removeAttribute('data-theme')
    }
  }, [known])
}
