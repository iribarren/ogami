import { FlowDiagramError } from '@/shared/flowDiagram'

import { manualFlowDiagram, manualPresets } from './flowDiagrams'

describe('manualFlowDiagram', () => {
  it('builds the whole of each manual flow from its preset', () => {
    const coldBerth = manualFlowDiagram('cold-berth')
    const longCarry = manualFlowDiagram('the-long-carry')

    expect(coldBerth.key).toBe('cold-berth')
    expect(coldBerth.phases.map((phase) => phase.key)).toContain('the-ship')
    expect(longCarry.key).toBe('the-long-carry')
    expect(longCarry.phases.map((phase) => phase.key)).toEqual(
      expect.arrayContaining(['session-zero', 'the-road']),
    )
  })

  it('cuts one phase out when the directive names it', () => {
    const cut = manualFlowDiagram('the-long-carry', 'the-road')

    expect(cut.phases.map((phase) => phase.key)).toEqual(['the-road'])
  })

  it('throws on an unknown flow, naming it', () => {
    expect(() => manualFlowDiagram('no-such-flow')).toThrow(/"no-such-flow"/)
  })

  it('throws on an unknown phase, naming it', () => {
    expect(() => manualFlowDiagram('cold-berth', 'no-such-phase')).toThrow(FlowDiagramError)
    expect(() => manualFlowDiagram('cold-berth', 'no-such-phase')).toThrow(/"no-such-phase"/)
  })

  it('reads the two manual presets', () => {
    expect(Object.keys(manualPresets).sort()).toEqual(['dogwatch', 'hard-country'])
  })
})
