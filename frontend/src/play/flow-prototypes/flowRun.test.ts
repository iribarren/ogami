import {
  addNote,
  advance,
  currentStep,
  progress,
  replay,
  startRun,
  upcomingSteps,
  type FlowRunState,
} from './flowRun'
import { sunkenGateSession } from './sunkenGate'

const sceneSet = { kind: 'text', text: 'Wet stone and a rusted portcullis.' } as const

function upcomingTitles(state: FlowRunState) {
  return upcomingSteps(state).map(({ when, step }) => ({ when, title: step?.title ?? null }))
}

describe('flowRun', () => {
  it('starts at the first step with an empty history and journal', () => {
    const state = startRun(sunkenGateSession())

    expect(currentStep(state)?.id).toBe('set-scene')
    expect(state.history).toEqual([])
    expect(state.entries).toEqual([])
  })

  it('records a prompt answer as a note in the scene and moves to its next step', () => {
    const state = advance(startRun(sunkenGateSession()), sceneSet)

    expect(currentStep(state)?.id).toBe('ask-guard')
    expect(state.entries).toHaveLength(1)
    expect(state.entries[0]).toMatchObject({
      sessionNumber: 1,
      sceneNumber: 1,
      kind: 'note',
      content: { kind: 'note', text: 'Wet stone and a rusted portcullis.' },
    })
    expect(state.history).toEqual([
      {
        stepId: 'set-scene',
        title: 'Set the scene',
        summary: 'Wet stone and a rusted portcullis.',
        entryId: state.entries[0]?.id,
      },
    ])
  })

  it('gives every recorded entry a distinct id and a later time', () => {
    const state = replay(sunkenGateSession(), [sceneSet, { kind: 'ask' }])
    const [first, second] = state.entries

    expect(first?.id).not.toBe(second?.id)
    expect(Date.parse(second?.recordedAt ?? '')).toBeGreaterThan(
      Date.parse(first?.recordedAt ?? ''),
    )
  })

  it('records the scripted oracle answer and branches on a no', () => {
    const state = replay(sunkenGateSession(), [sceneSet, { kind: 'ask' }])

    expect(state.entries.at(-1)?.content).toEqual({
      kind: 'likelihood',
      oracleKey: 'ask-guard',
      oracleName: 'Fate question',
      question: 'Is the gate guarded?',
      answer: 'no',
      roll: 64,
      sides: 100,
      effectiveTarget: 25,
      likelihood: 'unlikely',
      likelihoodLabel: 'Unlikely',
      chaosFactor: 5,
    })
    expect(state.history.at(-1)?.summary).toBe('Is the gate guarded? No')
    expect(currentStep(state)?.id).toBe('choose-way')
  })

  it('branches on a yes to a different step', () => {
    const state = replay(sunkenGateSession({ gateGuarded: true }), [sceneSet, { kind: 'ask' }])

    expect(state.history.at(-1)?.summary).toBe('Is the gate guarded? Yes')
    expect(currentStep(state)?.id).toBe('face-guard')
  })

  it('records a choice as a note and follows the chosen option', () => {
    const atChoice = replay(sunkenGateSession(), [sceneSet, { kind: 'ask' }])

    const swimming = advance(atChoice, { kind: 'choose', optionId: 'swim' })
    const climbing = advance(atChoice, { kind: 'choose', optionId: 'climb' })

    expect(currentStep(swimming)?.id).toBe('swim-roll')
    expect(currentStep(climbing)?.id).toBe('climb-roll')
    expect(swimming.entries.at(-1)?.content).toEqual({
      kind: 'note',
      text: 'Chose: Swim under the flooded arch',
    })
    expect(swimming.history.at(-1)?.summary).toBe('Swim under the flooded arch')
  })

  it('records the scripted roll', () => {
    const state = replay(sunkenGateSession(), [
      sceneSet,
      { kind: 'ask' },
      { kind: 'choose', optionId: 'swim' },
      { kind: 'roll' },
    ])

    expect(state.entries.at(-1)?.content).toEqual({
      kind: 'roll',
      expression: '1d20+2',
      total: 16,
      groups: [{ notation: '1d20', sides: 20, dice: [{ value: 14, kept: true }], subtotal: 14 }],
    })
    expect(state.history.at(-1)?.summary).toBe('1d20+2 = 16')
    expect(currentStep(state)?.id).toBe('close-scene')
  })

  it('finishes the scene after the last step', () => {
    const state = replay(sunkenGateSession(), [
      sceneSet,
      { kind: 'ask' },
      { kind: 'choose', optionId: 'climb' },
      { kind: 'roll' },
      { kind: 'text', text: 'A stair winding down.' },
    ])

    expect(currentStep(state)).toBeNull()
    expect(state.history.map(({ stepId }) => stepId)).toEqual([
      'set-scene',
      'ask-guard',
      'choose-way',
      'climb-roll',
      'close-scene',
    ])
    expect(upcomingSteps(state)).toEqual([])
  })

  it('refuses an input that does not answer the current step', () => {
    const state = startRun(sunkenGateSession())

    expect(() => advance(state, { kind: 'roll' })).toThrow()
    expect(() => advance(state, { kind: 'text', text: '   ' })).toThrow()
    expect(() =>
      advance(replay(sunkenGateSession(), [sceneSet, { kind: 'ask' }]), {
        kind: 'choose',
        optionId: 'fly',
      }),
    ).toThrow()
  })

  it('refuses to advance a finished scene', () => {
    const finished = replay(sunkenGateSession(), [
      sceneSet,
      { kind: 'ask' },
      { kind: 'choose', optionId: 'climb' },
      { kind: 'roll' },
      { kind: 'text', text: 'Done.' },
    ])

    expect(() => advance(finished, { kind: 'text', text: 'More.' })).toThrow()
  })

  it('records a free note without moving the flow', () => {
    const atOracle = advance(startRun(sunkenGateSession()), sceneSet)
    const state = addNote(atOracle, '  A gull screams overhead.  ')

    expect(currentStep(state)?.id).toBe('ask-guard')
    expect(state.history).toEqual(atOracle.history)
    expect(state.entries.at(-1)?.content).toEqual({
      kind: 'note',
      text: 'A gull screams overhead.',
    })
    expect(() => addNote(atOracle, ' ')).toThrow()
  })

  it('shows every possible next step, labelled by branch', () => {
    const start = startRun(sunkenGateSession())
    const atOracle = advance(start, sceneSet)
    const atChoice = advance(atOracle, { kind: 'ask' })
    const atLast = replay(sunkenGateSession(), [
      sceneSet,
      { kind: 'ask' },
      { kind: 'choose', optionId: 'swim' },
      { kind: 'roll' },
    ])

    expect(upcomingTitles(start)).toEqual([{ when: undefined, title: 'Ask the oracle' }])
    expect(upcomingTitles(atOracle)).toEqual([
      { when: 'Yes', title: 'Describe the guard' },
      { when: 'No', title: 'Choose a way in' },
    ])
    expect(upcomingTitles(atChoice)).toEqual([
      { when: 'Swim under the flooded arch', title: 'Roll to swim' },
      { when: 'Climb the broken wall', title: 'Roll to climb' },
    ])
    expect(upcomingTitles(atLast)).toEqual([{ when: undefined, title: null }])
  })

  it('numbers the current step and estimates the scene length over every branch', () => {
    const start = startRun(sunkenGateSession())
    const pastNo = replay(sunkenGateSession(), [sceneSet, { kind: 'ask' }])

    expect(progress(start)).toEqual({ number: 1, min: 5, max: 6 })
    expect(progress(pastNo)).toEqual({ number: 3, min: 5, max: 5 })
  })
})
