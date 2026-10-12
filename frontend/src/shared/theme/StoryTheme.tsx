import { useEffect, type ReactNode } from 'react'

import { ColorModeProvider } from './ColorModeProvider'
import { useCampaignTheme } from './useCampaignTheme'
import { useColorMode } from './useColorMode'

interface StoryThemeProps {
  /** The Storybook toolbar's color mode. */
  colorMode: 'light' | 'dark'
  /** The Storybook toolbar's theme: `default` or a curated campaign theme. */
  theme: string
  children: ReactNode
}

/**
 * Storybook's stand-in for the app's color mode and campaign theme: the toolbar mode drives the
 * real `ColorModeProvider`, and a curated theme goes through `useCampaignTheme`, so choosing one
 * also forces its mode (a dark theme can never show in light mode), exactly as in a campaign.
 */
export function StoryTheme({ colorMode, theme, children }: StoryThemeProps) {
  return (
    <ColorModeProvider>
      <ToolbarSync colorMode={colorMode} theme={theme}>
        {children}
      </ToolbarSync>
    </ColorModeProvider>
  )
}

function ToolbarSync({ colorMode, theme, children }: StoryThemeProps) {
  const { setMode } = useColorMode()
  useEffect(() => {
    setMode(colorMode)
  }, [colorMode, setMode])
  useCampaignTheme(theme === 'default' ? null : theme)
  return children
}
