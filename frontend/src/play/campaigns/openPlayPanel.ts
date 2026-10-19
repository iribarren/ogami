/** The event that asks the Play panel with this `id` to open and come into view. */
export const openPlayPanelEvent = 'ogami:open-play-panel'

/** Opens the Play panel (`threads`, `npcs`…) wherever it is on the page, expanding it if collapsed. */
export function openPlayPanel(id: string): void {
  window.dispatchEvent(new CustomEvent<string>(openPlayPanelEvent, { detail: id }))
}
