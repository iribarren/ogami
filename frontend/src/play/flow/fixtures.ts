import { lostMineCampaign } from '../campaigns/fixtures'
import type { Campaign } from '../campaigns/useCampaigns'
import type { FlowRun, FlowStep } from './useFlowRun'

// Shared by the guided panel's tests and stories; the campaign is `lostMineCampaign` along the Flow "tour".

const tour = { key: 'tour', name: 'Tour', purpose: 'Meet someone on the road.' }
const quiet = { key: 'quiet', name: 'Quiet', purpose: 'Rest and take stock.' }

/** A step with nothing set but its key and title; the kinds below fill in what they need. */
function step(fields: Partial<FlowStep> & Pick<FlowStep, 'key' | 'kind' | 'title'>): FlowStep {
  return {
    prompt: null,
    tip: null,
    mandatory: false,
    oracle: null,
    likelihood: null,
    table: null,
    dice: null,
    options: [],
    ...fields,
  }
}

export const promptStep = step({
  key: 'intro',
  kind: 'prompt',
  title: 'Who walks in?',
  prompt: 'Describe the first person.',
  tip: 'Keep it short.',
})
export const oracleStep = step({
  key: 'ask',
  kind: 'oracle',
  title: 'Is the gate open?',
  oracle: 'fate',
})
export const fixedOracleStep = { ...oracleStep, key: 'ask-even', likelihood: 'even' }
export const tableStep = step({
  key: 'omen',
  kind: 'table',
  title: 'Read the omen',
  table: 'weather',
})
export const rollStep = step({ key: 'dice', kind: 'roll', title: 'Push your luck', dice: '1d6' })
export const choiceStep = step({
  key: 'fork',
  kind: 'choice',
  title: 'Which way?',
  options: [
    { key: 'left', label: 'Go left' },
    { key: 'right', label: 'Go right' },
  ],
})

const progress: FlowRun['progress'] = {
  act: 'Act 1',
  phaseKey: 'start',
  phase: 'The start',
  sceneType: 'Tour',
  part: 'setup',
  stepNumber: 1,
  stepCount: 3,
}

/** A FlowRun waiting on `current`, in the scene "Into the dark" (the Scene Type Tour). */
export function atStep(current: FlowStep, overrides: Partial<FlowRun> = {}): FlowRun {
  return {
    status: 'active',
    waitsForSession: false,
    progress,
    step: current,
    pick: null,
    canMoveOn: false,
    next: `Setup: ${current.title}`,
    canEndSession: false,
    ...overrides,
  }
}

/** A FlowRun at the scene pick, in `rule`'s words. */
export const atTheScenePick = (overrides: Partial<NonNullable<FlowRun['pick']>> = {}): FlowRun =>
  atStep(promptStep, {
    progress: { ...progress, sceneType: null, part: null, stepNumber: null, stepCount: null },
    step: null,
    pick: { rule: 'player', cards: [tour, quiet], table: null, forced: false, ...overrides },
    next: 'Next scene: choose a scene type',
    canEndSession: true,
  })

/** Open play in the scene: no step waits. */
export const inOpenPlay: FlowRun = atStep(promptStep, {
  step: null,
  progress: { ...progress, part: 'open', stepNumber: null, stepCount: null },
  next: 'Closing: What changed?',
})

/** `lostMineCampaign` along the Flow "tour", with the given FlowRun. */
export function guided(flowRun: FlowRun): Campaign {
  return {
    ...lostMineCampaign,
    flowKey: 'tour',
    flowRun,
    flows: [
      {
        key: 'tour',
        name: 'Tour',
        description: null,
        introduction: null,
        default: true,
        defaultView: 'journal',
      },
    ],
    sceneTypes: [tour, quiet],
  }
}
