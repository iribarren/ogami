/**
 * jsdom has no `matchMedia`. Installs a controllable one for `prefers-color-scheme: dark`
 * and returns `setDark` to flip the system preference and notify listeners.
 */
export function mockSystemColorScheme(initialDark: boolean) {
  let dark = initialDark
  const listeners = new Set<(event: MediaQueryListEvent) => void>()

  const matchMedia = (query: string) =>
    ({
      get matches() {
        return query.includes('dark') ? dark : !dark
      },
      media: query,
      addEventListener: (_type: string, listener: (event: MediaQueryListEvent) => void) => {
        listeners.add(listener)
      },
      removeEventListener: (_type: string, listener: (event: MediaQueryListEvent) => void) => {
        listeners.delete(listener)
      },
    }) as MediaQueryList
  Object.defineProperty(window, 'matchMedia', {
    value: matchMedia,
    writable: true,
    configurable: true,
  })

  return {
    setDark(next: boolean) {
      dark = next
      listeners.forEach((listener) => {
        listener({ matches: next } as MediaQueryListEvent)
      })
    },
    listenerCount: () => listeners.size,
  }
}
