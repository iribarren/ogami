import { describe, expect, it } from 'vitest'

import { describeFlowDiagram } from './describeFlowDiagram'
import { flowDiagramFromReleaseContent } from './fromReleaseContent'
import { phaseDiagram } from './phaseDiagram'
import { loadPreset, phase, release, sceneType, step, table } from './testSupport'

const coldBerth = flowDiagramFromReleaseContent(loadPreset('dogwatch'), 'cold-berth')

describe('describeFlowDiagram', () => {
  it('describes the phases of Cold Berth in order', () => {
    const { title, phases } = describeFlowDiagram(coldBerth)
    expect(title).toBe('Cold Berth')
    expect(phases.map((item) => item.title)).toEqual([
      'Session Zero (once)',
      'Wake (once)',
      'The Ship (loop)',
      'Reveal (once)',
      'Epilogue (once)',
    ])
  })

  it('describes the selection, Scene Types and hooks of The Ship', () => {
    const ship = describeFlowDiagram(phaseDiagram(coldBerth, 'the-ship')).phases[0]
    expect(ship).toEqual({
      title: 'The Ship (loop)',
      selection: 'Player chooses',
      sceneTypes: ['Investigate', 'Repair', 'Confront', 'Hide'],
      hooks: ['Scene closing (2 steps)', 'World turn (4 steps)'],
    })
  })

  it('lists every transition with its source, kind and target', () => {
    expect(describeFlowDiagram(phaseDiagram(coldBerth, 'the-ship')).transitions).toEqual([
      'Scene closing (The Ship) → ends the phase: Reveal',
      'Ship trouble (world turn, The Ship) → next scene: Repair',
      'Ship trouble (world turn, The Ship) → next scene: Hide',
      'Ship trouble (world turn, The Ship) → next scene: Investigate',
      'Ship trouble (world turn, The Ship) → next scene: Confront',
    ])
  })

  it('says how an oracle and a sequence select', () => {
    const longCarry = flowDiagramFromReleaseContent(loadPreset('hard-country'), 'the-long-carry')
    const selections = describeFlowDiagram(longCarry).phases.map((item) => item.selection)
    expect(selections).toEqual(['In order', 'Drawn from The road deck', 'In order', 'In order'])
  })

  it('words switches, reached Scene Types, singular steps, the flow end and selection table entries', () => {
    const content = release({
      sceneTypes: [
        sceneType('a', {
          closing: [
            step('s', {
              effects: [{ kind: 'switchSceneType', sceneType: 'b' }, { kind: 'endPhase' }],
            }),
          ],
        }),
        sceneType('b'),
        sceneType('c'),
      ],
      tables: [
        table('deck', [
          { text: 'A', sceneType: 'a', effects: [{ kind: 'nextScene', sceneType: 'c' }] },
        ]),
      ],
      phases: [
        phase('only', {
          name: 'The Only',
          act: 'Act One',
          mode: 'loop',
          selection: { rule: 'oracle', table: 'deck' },
          sceneClosing: [step('x')],
        }),
      ],
    })
    const description = describeFlowDiagram(flowDiagramFromReleaseContent(content, 'f'))
    expect(description.phases[0]).toEqual({
      title: 'The Only (loop, Act One)',
      selection: 'Drawn from DECK',
      // The selection table's own effect reaches C before A's closing reaches B.
      sceneTypes: ['A', 'C (reached by a transition)', 'B (reached by a transition)'],
      hooks: ['Scene closing (1 step)'],
    })
    expect(description.transitions).toEqual([
      'DECK (The Only) → next scene: C',
      'A (The Only) → switches the scene to: B',
      'A (The Only) → ends the phase and the flow',
    ])
  })

  it('is deterministic', () => {
    expect(describeFlowDiagram(coldBerth)).toEqual(describeFlowDiagram(coldBerth))
  })
})
