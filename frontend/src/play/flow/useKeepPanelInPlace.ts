import { useEffect, useRef } from 'react'

/**
 * In the Journal view the guided panel sits below the Journal, so every new entry pushes it down
 * and the button under the player's pointer moves. This keeps the panel where it is: when the
 * Journal's height changes, the window scrolls by the same delta, as long as the panel was on
 * screen before the change. It reacts to height changes only, so the player's own scrolling never
 * triggers it, and the first measurement (the initial load) is ignored. Without `ResizeObserver`
 * it does nothing. `active` is false when the Journal is not on the page (focus mode).
 */
export function useKeepPanelInPlace(active: boolean) {
  const journalRef = useRef<HTMLDivElement>(null)
  const panelRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const journal = journalRef.current
    if (!active || journal === null || typeof ResizeObserver === 'undefined') {
      return
    }
    let previous: number | null = null
    const observer = new ResizeObserver((entries) => {
      const latest = entries.at(-1)
      if (latest === undefined) {
        return
      }
      const height = latest.contentRect.height
      const last = previous
      previous = height
      if (last === null || height === last) {
        return
      }
      const delta = height - last
      const panel = panelRef.current
      if (panel === null) {
        return
      }
      // The panel has moved by `delta` already: judge where it was before the change.
      const before = panel.getBoundingClientRect().top - delta
      if (before < 0 || before >= window.innerHeight) {
        return
      }
      window.scrollBy({ top: delta, behavior: 'instant' })
    })
    observer.observe(journal)
    return () => {
      observer.disconnect()
    }
  }, [active])

  return { journalRef, panelRef }
}
