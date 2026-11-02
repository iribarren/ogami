import { render } from '@testing-library/react'
import type { MockInstance } from 'vitest'

import { useKeepPanelInPlace } from './useKeepPanelInPlace'

type Callback = (entries: { contentRect: { height: number } }[]) => void

let callback: Callback | null = null
let disconnected = 0

class FakeResizeObserver {
  constructor(cb: Callback) {
    callback = cb
  }
  observe() {
    return undefined
  }
  unobserve() {
    return undefined
  }
  disconnect() {
    disconnected += 1
  }
}

function Harness({ active = true, panelTop }: { active?: boolean; panelTop: number }) {
  const { journalRef, panelRef } = useKeepPanelInPlace(active)
  return (
    <>
      <div ref={journalRef}>
        <button type="button">Expand entry</button>
      </div>
      <div
        ref={(element) => {
          if (element !== null) {
            element.getBoundingClientRect = () =>
              ({ top: panelTop, bottom: panelTop + 400 }) as DOMRect
          }
          panelRef.current = element
        }}
      >
        panel
      </div>
    </>
  )
}

/** Fires the observer as the browser would after the Journal got this tall. */
function resizeTo(height: number) {
  if (callback === null) {
    throw new Error('No observer was created.')
  }
  callback([{ contentRect: { height } }])
}

describe('keeping the guided panel in place', () => {
  let scrollBy: MockInstance<typeof window.scrollBy>

  beforeEach(() => {
    callback = null
    disconnected = 0
    vi.stubGlobal('ResizeObserver', FakeResizeObserver)
    scrollBy = vi.spyOn(window, 'scrollBy').mockImplementation(() => undefined)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    scrollBy.mockRestore()
  })

  it('does not scroll on the first measurement', () => {
    render(<Harness panelTop={300} />)
    resizeTo(500)

    expect(scrollBy).not.toHaveBeenCalled()
  })

  it('scrolls by the growth while the panel is on screen', () => {
    // The panel was at 300 before the 92 px growth, so it now sits at 392.
    render(<Harness panelTop={392} />)
    resizeTo(500)
    resizeTo(592)

    expect(scrollBy).toHaveBeenCalledExactlyOnceWith({ top: 92, behavior: 'instant' })
  })

  it('scrolls back by the shrinkage', () => {
    render(<Harness panelTop={208} />)
    resizeTo(500)
    resizeTo(408)

    expect(scrollBy).toHaveBeenCalledExactlyOnceWith({ top: -92, behavior: 'instant' })
  })

  it('does not scroll when the panel was off screen', () => {
    render(<Harness panelTop={window.innerHeight + 1000} />)
    resizeTo(500)
    resizeTo(592)

    expect(scrollBy).not.toHaveBeenCalled()
  })

  it('scrolls while the panel top is above the viewport but its actions are still on screen', () => {
    render(<Harness panelTop={-200} />)
    resizeTo(500)
    resizeTo(592)

    expect(scrollBy).toHaveBeenCalledExactlyOnceWith({ top: 92, behavior: 'instant' })
  })

  it('does not scroll when the whole panel was above the viewport', () => {
    render(<Harness panelTop={-1000} />)
    resizeTo(500)
    resizeTo(592)

    expect(scrollBy).not.toHaveBeenCalled()
  })

  it('does not scroll when the player is using a control inside the Journal', () => {
    const { getByRole } = render(<Harness panelTop={300} />)
    resizeTo(500)
    getByRole('button', { name: 'Expand entry' }).focus()
    resizeTo(592)

    expect(scrollBy).not.toHaveBeenCalled()
  })

  it('does not scroll when the height did not change', () => {
    render(<Harness panelTop={300} />)
    resizeTo(500)
    resizeTo(500)

    expect(scrollBy).not.toHaveBeenCalled()
  })

  it('observes nothing when inactive, and disconnects on unmount', () => {
    const inactive = render(<Harness active={false} panelTop={300} />)
    expect(callback).toBeNull()
    inactive.unmount()

    const { unmount } = render(<Harness panelTop={300} />)
    unmount()
    expect(disconnected).toBe(1)
  })

  it('takes its first measurement when it becomes active, so the Journal loading does not scroll', () => {
    const { rerender } = render(<Harness active={false} panelTop={300} />)
    expect(callback).toBeNull()

    rerender(<Harness panelTop={300} />)
    resizeTo(11000)
    expect(scrollBy).not.toHaveBeenCalled()

    resizeTo(11092)
    expect(scrollBy).toHaveBeenCalledExactlyOnceWith({ top: 92, behavior: 'instant' })
  })

  it('does nothing without ResizeObserver', () => {
    vi.stubGlobal('ResizeObserver', undefined)

    expect(() => render(<Harness panelTop={300} />)).not.toThrow()
    expect(scrollBy).not.toHaveBeenCalled()
  })
})
