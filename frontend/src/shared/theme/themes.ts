/**
 * The curated campaign Themes a GameSystem release may name (docs/contracts/gamesystem-release.md,
 * "Theme"), each with its fixed color mode. Inside a themed Campaign the theme's mode wins over the
 * player's choice, so `dark:` utilities and the palette always agree. The palettes themselves are
 * `:root[data-theme="<name>"]` rules in `index.css`.
 */
export const CAMPAIGN_THEMES = {
  parchment: 'light',
  neon: 'dark',
  noir: 'dark',
  eldritch: 'dark',
  starship: 'light',
} as const

export type CampaignTheme = keyof typeof CAMPAIGN_THEMES

export const CAMPAIGN_THEME_NAMES = Object.keys(CAMPAIGN_THEMES) as CampaignTheme[]

/** Narrows a name from the API: anything outside the curated set is not a theme. */
export function isCampaignTheme(value: unknown): value is CampaignTheme {
  return typeof value === 'string' && Object.hasOwn(CAMPAIGN_THEMES, value)
}
