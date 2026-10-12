export type ColorMode = 'light' | 'dark' | 'system'

/** Read by the inline script in index.html too: keep the key and the values in sync. */
export const COLOR_MODE_STORAGE_KEY = 'ogami.colorMode'

export function isColorMode(value: unknown): value is ColorMode {
  return value === 'light' || value === 'dark' || value === 'system'
}

/** The stored mode, or `system` when nothing usable is stored or storage is unavailable. */
export function readStoredColorMode(): ColorMode {
  try {
    const stored = localStorage.getItem(COLOR_MODE_STORAGE_KEY)
    return isColorMode(stored) ? stored : 'system'
  } catch {
    return 'system'
  }
}

export function storeColorMode(mode: ColorMode): void {
  try {
    localStorage.setItem(COLOR_MODE_STORAGE_KEY, mode)
  } catch {
    // Storage can be blocked; the mode then lasts until the page closes.
  }
}
