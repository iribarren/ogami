import { step } from '@/shared/flowDiagram/testSupport'

import { newEffect } from '../steps/effectDraft'
import { flowToDraft, type PhaseDraft } from './flowDraft'
import { addEffect, describeEffect, sceneEffect, stepLabel } from './flowMapCarriers'
import { isRefusal } from './flowMapEdits'

const phasesOf = (hooks: Record<string, unknown>): PhaseDraft[] => {
  const draft = flowToDraft({
    key: 'f',
    name: 'F',
    defaultView: 'focus',
    oracles: [],
    trackers: [],
    phases: [
      {
        key: 'one',
        name: 'One',
        mode: 'once',
        selection: { rule: 'sequence', sceneTypes: [] },
        ...hooks,
      },
    ],
  })
  if (draft === null) {
    throw new Error('Expected a readable flow.')
  }
  return draft.phases
}

const names = (key: string) => key.toUpperCase()
const next = (sceneType: string) => sceneEffect('nextScene', sceneType)
const at = { phase: 0, hook: 'phaseOpening', step: 0 } as const

describe('addEffect', () => {
  it('appends the effect to the step and leaves every other step and phase alone', () => {
    const phases = phasesOf({ phaseOpening: [step('a'), step('b')], worldTurn: [step('w')] })

    const result = addEffect(phases, { ...at, step: 1 }, 'step', next('x'), names)

    if (isRefusal(result)) {
      throw new Error(result.refused)
    }
    const steps = result.phases[0]?.hooks.phaseOpening
    expect(steps?.[0]).toEqual(phases[0]?.hooks.phaseOpening?.[0])
    expect(steps?.[1]).toMatchObject({ effects: [{ kind: 'nextScene', sceneType: 'x' }] })
    expect(result.phases[0]?.hooks.worldTurn).toBe(phases[0]?.hooks.worldTurn)
    expect(result.say).toBe(
      'Added next scene "X" to step "b" of the hook "Phase opening" of phase "One".',
    )
    expect(phases[0]?.hooks.phaseOpening?.[1]).toMatchObject({ effects: [] })
  })

  it('refuses a hook with no step, a step that is not there, and a part the step lacks', () => {
    const phases = phasesOf({ phaseOpening: [step('a')] })

    expect(addEffect(phases, { ...at, hook: 'worldTurn' }, 'step', next('x'), names)).toEqual({
      refused:
        'The hook "World turn" of phase "One" has no step yet: add a step in its panel first.',
    })
    expect(addEffect(phases, { ...at, step: 4 }, 'step', next('x'), names)).toEqual({
      refused: 'The hook "Phase opening" of phase "One" has no such step.',
    })
    expect(addEffect(phases, at, 'band:0', next('x'), names)).toEqual({
      refused: 'Step "a" has no such part.',
    })
    expect(addEffect(phases, { ...at, phase: 3 }, 'step', next('x'), names)).toEqual({
      refused: 'That phase is not in the flow.',
    })
  })

  it('refuses a step kept as stored, since the editor cannot write to it', () => {
    const phases = phasesOf({ phaseOpening: [{ kind: 'mystery' }] })

    expect(addEffect(phases, at, 'step', next('x'), names)).toEqual({
      refused:
        'Step "Step 1" of the hook "Phase opening" of phase "One" cannot be edited here: open its panel.',
    })
  })

  it('refuses an effect the part has already, and a part with the most effects', () => {
    const twin = { effects: [{ kind: 'nextScene', sceneType: 'x' }] }
    const full = { effects: Array.from({ length: 10 }, () => ({ kind: 'endPhase' })) }
    const phases = phasesOf({ phaseOpening: [step('a', twin)], worldTurn: [step('w', full)] })

    expect(addEffect(phases, at, 'step', next('x'), names)).toEqual({
      refused: 'Step "a" already has this effect: next scene "X".',
    })
    expect(addEffect(phases, at, 'step', next('y'), names)).not.toHaveProperty('refused')
    expect(addEffect(phases, { ...at, hook: 'worldTurn' }, 'step', next('y'), names)).toEqual({
      refused: 'The step itself of step "w" has 10 effects, the most it may.',
    })
    expect(
      addEffect(phases, { ...at, hook: 'worldTurn' }, 'step', newEffect('endPhase'), names),
    ).toMatchObject({ refused: 'Step "w" already has this effect: end the phase.' })
  })
})

describe('describeEffect and stepLabel', () => {
  it('say what an effect does and what a step is called', () => {
    expect(describeEffect(newEffect('endPhase'), names)).toBe('end the phase')
    expect(describeEffect(sceneEffect('switchSceneType', 'q'), names)).toBe(
      'switch to Scene Type "Q"',
    )
    const [first] =
      phasesOf({ phaseOpening: [step('k', { title: '  ' })] })[0]?.hooks.phaseOpening ?? []
    if (first === undefined) {
      throw new Error('Expected a step.')
    }
    expect(stepLabel(first, 0)).toBe('"k"')
  })
})
