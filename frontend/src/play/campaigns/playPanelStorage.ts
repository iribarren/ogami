/** `localStorage` key of one Play panel's open/closed state: `ogami.playPanel.<id>`. */
export const playPanelStorageKey = (id: string) => `ogami.playPanel.${id}`

/** Whether the panel is open: remembered if usable, else open (also without storage). */
export function readPanelOpen(id: string): boolean {
  try {
    return localStorage.getItem(playPanelStorageKey(id)) !== 'closed'
  } catch {
    return true
  }
}

export function storePanelOpen(id: string, open: boolean): void {
  try {
    localStorage.setItem(playPanelStorageKey(id), open ? 'open' : 'closed')
  } catch {
    // Storage can be blocked; the panel then keeps its state until the page closes.
  }
}
