import { progressText } from './progress'
import type { FlowRun } from './useFlowRun'

type Progress = NonNullable<FlowRun['progress']>

const progress: Progress = {
  act: null,
  phaseKey: 'the-letter',
  phase: 'The letter',
  sceneType: 'The letter',
  part: 'open',
  stepNumber: null,
  stepCount: null,
}

describe('progressText', () => {
  it('names the act, phase, Scene Type and part, with the step when one counts', () => {
    expect(
      progressText({
        ...progress,
        act: 'Act 1',
        phase: 'The start',
        sceneType: 'Tour',
        part: 'setup',
        stepNumber: 1,
        stepCount: 3,
      }),
    ).toBe('Act 1 › The start › Tour › Setup · step 1 of 3')
  })

  it('names a phase and a Scene Type that read the same once', () => {
    expect(progressText(progress)).toBe('The letter › Open play')
  })

  it('compares names ignoring case and a leading "The"', () => {
    expect(progressText({ ...progress, phase: 'Letter', sceneType: 'the LETTER' })).toBe(
      'Letter › Open play',
    )
  })

  it('keeps names that differ, and the same name when something else sits between', () => {
    expect(progressText({ ...progress, act: 'The letter', phase: 'Arrival' })).toBe(
      'The letter › Arrival › The letter › Open play',
    )
  })

  it('keeps a name that merely starts like the one before it', () => {
    expect(progressText({ ...progress, sceneType: 'The letter arrives' })).toBe(
      'The letter › The letter arrives › Open play',
    )
  })
})
