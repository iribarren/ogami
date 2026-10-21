/** The sections of the GameSystem page, in the order the rail lists them; the `tab` search parameter. */
export const gameSystemTabs = [
  'overview',
  'oracles',
  'narrative',
  'sceneTypes',
  'flows',
  'sheet',
  'checks',
  'library',
  'validation',
  'releases',
  'sections',
] as const

export type GameSystemTab = (typeof gameSystemTabs)[number]

/** A known section, or `undefined` for anything else: the page then opens the Overview. */
export function parseGameSystemTab(value: unknown): GameSystemTab | undefined {
  return gameSystemTabs.find((tab) => tab === value)
}
