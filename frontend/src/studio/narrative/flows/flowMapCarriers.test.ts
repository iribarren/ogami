import { step } from '@/shared/flowDiagram/testSupport'

import { newEffect } from '../steps/effectDraft'
import { stepsToDrafts } from '../steps/stepDraft'
import { flowToDraft, type PhaseDraft } from './flowDraft'
import {
  addEffect,
  carriersOf,
  describeEffect,
  hookSteps,
  sceneEffect,
  stepLabel,
} from './flowMapCarriers'
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

describe('carriersOf', () => {
  const [roll, choice, oracle, pick, table, check, prompt, mystery] = stepsToDrafts([
    {
      key: 'r',
      kind: 'roll',
      title: 'R',
      dice: '1d6',
      bands: [{ upTo: 3, label: 'Hard' }, { upTo: 5 }, { label: 'rest' }],
    },
    {
      key: 'c',
      kind: 'choice',
      title: 'C',
      options: [
        { key: 'run', label: 'Run' },
        { key: 'hide', label: '' },
      ],
    },
    { key: 'o', kind: 'oracle', title: 'O', oracle: 'fate' },
    { key: 'p', kind: 'pick', title: 'P', of: 'npc', mode: 'random' },
    {
      key: 't',
      kind: 'table',
      title: 'T',
      table: 'places',
      branches: [{ entry: 'market' }, { entry: '' }],
    },
    {
      key: 'k',
      kind: 'check',
      title: 'K',
      check: 'skill',
      outcomes: [{ band: 'fail' }, { special: 'critical' }],
    },
    { key: 'q', kind: 'prompt', title: 'Q' },
    { kind: 'mystery' },
  ])
  const labels = (step: typeof roll) =>
    step === undefined ? [] : carriersOf(step).map(({ label }) => label)
  const ids = (step: typeof roll) =>
    step === undefined ? [] : carriersOf(step).map(({ id }) => id)

  it('names the step itself and each band, option and outcome by what the author wrote', () => {
    expect(labels(roll)).toEqual([
      'The step itself',
      'Up to 3: Hard',
      'Up to 5',
      'Any other result: rest',
    ])
    expect(labels(choice)).toEqual(['The step itself', 'Option "Run"', 'Option "hide"'])
    expect(labels(oracle).slice(1)).toEqual([
      'Answer: Yes',
      'Answer: No',
      'Answer: Exceptional yes',
      'Answer: Exceptional no',
    ])
    expect(labels(pick).slice(1)).toEqual(['Outcome: Found', 'Outcome: None'])
    expect(labels(table).slice(1)).toEqual(['Entry "market"', 'Entry 2', 'Otherwise'])
    expect(labels(check).slice(1)).toEqual(['Band "fail"', 'Special result: Critical'])
  })

  it('gives a step with no outcomes only itself, and a step kept as stored nothing', () => {
    expect(ids(prompt)).toEqual(['step'])
    expect(ids(mystery)).toEqual([])
  })

  it('adds an effect to one band, one option or one outcome and nothing else', () => {
    const [first] = stepsToDrafts([
      { key: 'r', kind: 'roll', title: 'R', dice: '1d6', bands: [{ upTo: 3 }, { label: 'rest' }] },
    ])
    const [second] = stepsToDrafts([
      {
        key: 'c',
        kind: 'choice',
        title: 'C',
        options: [
          { key: 'a', label: 'A' },
          { key: 'b', label: 'B' },
        ],
      },
    ])
    const [third] = stepsToDrafts([{ key: 'o', kind: 'oracle', title: 'O', oracle: 'fate' }])
    if (first === undefined || second === undefined || third === undefined) {
      throw new Error('Expected steps.')
    }
    const phases = phasesOf({})
    const base = phases[0]
    if (base === undefined) {
      throw new Error('Expected a phase.')
    }
    const withSteps = [{ ...base, hooks: { phaseOpening: [first, second, third] } }]
    const put = (step: number, part: string) => {
      const result = addEffect(withSteps, { ...at, step }, part, next('x'), names)
      if (isRefusal(result)) {
        throw new Error(result.refused)
      }
      return { result, steps: result.phases[0]?.hooks.phaseOpening ?? [] }
    }

    const band = put(0, 'band:1')
    expect(band.steps[0]).toMatchObject({
      effects: [],
      bands: [{ effects: [] }, { effects: [{ kind: 'nextScene' }] }],
    })
    expect(band.result.say).toBe(
      'Added next scene "X" to step "R" of the hook "Phase opening" of phase "One".',
    )
    expect(put(1, 'option:0').steps[1]).toMatchObject({
      options: [{ effects: [{ kind: 'nextScene' }] }, { effects: [] }],
    })
    expect(put(2, 'oracle:exceptionalNo').steps[2]).toMatchObject({
      branches: { yes: { effects: [] }, exceptionalNo: { effects: [{ kind: 'nextScene' }] } },
    })
    expect(addEffect(withSteps, at, 'band:9', next('x'), names)).toEqual({
      refused: 'Step "R" has no such part.',
    })
  })
})

describe('hookSteps', () => {
  it('lists the steps, or says why the hook cannot carry a transition', () => {
    const phases = phasesOf({ phaseOpening: [{ kind: 'mystery' }], worldTurn: [step('w')] })

    expect(hookSteps(phases, { phase: 0, hook: 'worldTurn' })).toHaveLength(1)
    expect(hookSteps(phases, { phase: 0, hook: 'sessionOpening' })).toEqual({
      refused:
        'The hook "Session opening" of phase "One" has no step yet: add a step in its panel first.',
    })
    expect(hookSteps(phases, { phase: 0, hook: 'phaseOpening' })).toEqual({
      refused:
        'No step of the hook "Phase opening" of phase "One" can be edited here: open its panel.',
    })
    expect(hookSteps(phases, { phase: 2, hook: 'worldTurn' })).toEqual({
      refused: 'That phase is not in the flow.',
    })
  })
})
