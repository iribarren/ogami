import { describe, expect, it } from 'vitest'

import { FlowDiagramError } from './flowDiagram'
import { flowDiagramFromReleaseContent } from './fromReleaseContent'
import { phaseDiagram } from './phaseDiagram'
import { loadPreset } from './testSupport'

const diagram = flowDiagramFromReleaseContent(loadPreset('dogwatch'), 'cold-berth')

describe('phaseDiagram', () => {
  it('keeps one phase and its transitions', () => {
    const cut = phaseDiagram(diagram, 'the-ship')
    expect(cut.key).toBe('cold-berth')
    expect(cut.phases.map((phase) => phase.key)).toEqual(['the-ship'])
    expect(cut.transitions).toHaveLength(5)
    expect(cut.transitions.every((transition) => transition.phase === 'the-ship')).toBe(true)
  })

  it('keeps the next phase an ending transition points to, so the exit stub can name it', () => {
    const exit = phaseDiagram(diagram, 'the-ship').transitions.find(
      (transition) => transition.kind === 'endPhase',
    )
    expect(exit?.to).toEqual({ type: 'nextPhase', phase: 'reveal', name: 'Reveal' })
  })

  it('gives a phase without transitions an empty list and leaves the diagram untouched', () => {
    expect(phaseDiagram(diagram, 'wake').transitions).toEqual([])
    expect(diagram.phases).toHaveLength(5)
  })

  it('records the phase a cut continues to, and none for the last phase or a whole flow', () => {
    expect(phaseDiagram(diagram, 'wake').continuesTo).toEqual({ key: 'the-ship', name: 'The Ship' })
    expect(phaseDiagram(diagram, 'epilogue').continuesTo).toBeNull()
    expect(diagram.continuesTo).toBeUndefined()
  })

  it('throws for an unknown phase', () => {
    expect(() => phaseDiagram(diagram, 'nope')).toThrow(FlowDiagramError)
    expect(() => phaseDiagram(diagram, 'nope')).toThrow(
      /flows\[cold-berth\]\.phases: no phase "nope"/,
    )
  })
})
