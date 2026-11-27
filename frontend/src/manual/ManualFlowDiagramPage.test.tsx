import { render, screen, within } from '@testing-library/react'
import { vi } from 'vitest'

import { renderAppAt } from '@/test/renderApp'

import { ManualFlowDiagram } from './ManualFlowDiagram'

// A page of our own, so the test does not depend on where the chapters place their diagrams.
vi.mock('./pages', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./pages')>()),
  pageSegments: () => [
    { edition: 'common', kind: 'markdown', text: 'Before the diagram.' },
    { edition: 'common', kind: 'flow-diagram', flow: 'cold-berth' },
    { edition: 'common', kind: 'flow-diagram', flow: 'the-long-carry', phase: 'the-road' },
    { edition: 'in-ogami', kind: 'flow-diagram', flow: 'cold-berth', phase: 'the-ship' },
  ],
}))

describe('a flow diagram in a manual page', () => {
  it('draws each directive from the real presets, in an In Ogami block too', async () => {
    renderAppAt('/manual/how-to-use')

    const drawn = await screen.findAllByRole('img', { name: /^Flow diagram:/ })
    expect(drawn).toHaveLength(3)
    expect(drawn.every((image) => image.tagName.toLowerCase() === 'svg')).toBe(true)
    const callout = screen.getByRole('complementary', { name: 'In Ogami' })
    expect(within(callout).getByRole('img', { name: /^Flow diagram:/ })).toBeVisible()
  })

  it('fails loudly for a flow or a phase the presets do not have', () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined)

    expect(() => render(<ManualFlowDiagram flow="no-such-flow" />)).toThrow(/"no-such-flow"/)
    expect(() => render(<ManualFlowDiagram flow="cold-berth" phase="nowhere" />)).toThrow(
      /"nowhere"/,
    )
  })
})
