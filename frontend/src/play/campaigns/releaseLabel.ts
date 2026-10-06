/** "Free journal v2": the GameSystem name and release version a campaign is pinned to. */
export function releaseLabel(gameSystemName: string, version: number) {
  return `${gameSystemName} v${String(version)}`
}
