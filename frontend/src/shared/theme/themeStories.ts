import type { CampaignTheme } from './themes'

/**
 * Pins a story to one curated campaign theme (`globals: { theme }`), so a component can show how
 * every theme styles it without touching the toolbar. The theme also forces its own color mode.
 * The default story keeps the toolbar theme.
 */
export function onTheme<S extends object>(
  theme: CampaignTheme,
  story: S,
): S & { globals: { theme: CampaignTheme } } {
  return { ...story, globals: { theme } }
}
