import type { Campaign } from '../campaigns/useCampaigns'
import type { FlowRun } from './useFlowRun'

const partLabels: Record<NonNullable<NonNullable<FlowRun['progress']>['part']>, string> = {
  sceneOpening: 'Scene opening',
  setup: 'Setup',
  play: 'Play',
  open: 'Open play',
  closing: 'Closing',
  sceneClosing: 'Scene closing',
  sessionOpening: 'Session opening',
  sessionClosing: 'Session closing',
  phaseOpening: 'Phase opening',
  phaseClosing: 'Phase closing',
  worldTurn: 'World turn',
}

const hooks = new Set<string | null>([
  'sessionOpening',
  'sessionClosing',
  'phaseOpening',
  'phaseClosing',
  'worldTurn',
])

/** "Act 1 › The start › Tour › Setup · step 1 of 3", whatever the Flow tells. */
export function progressText(progress: NonNullable<FlowRun['progress']>) {
  const place = [
    progress.act,
    progress.phase,
    progress.sceneType,
    progress.part === null ? null : partLabels[progress.part],
  ].filter((part) => part !== null)
  const step =
    progress.stepNumber === null || progress.stepCount === null
      ? ''
      : ` · step ${String(progress.stepNumber)} of ${String(progress.stepCount)}`
  return `${place.join(' › ')}${step}`
}

/** How far through the part's steps the FlowRun is, from 0 to 100; null when no step counts. */
export function stepPercent(progress: NonNullable<FlowRun['progress']>) {
  if (progress.stepNumber === null || progress.stepCount === null || progress.stepCount === 0) {
    return null
  }
  return Math.round(((progress.stepNumber - 1) / progress.stepCount) * 100)
}

/** Open play in a scene of play: nothing waits, so the scene can end. */
export function canEndScene(campaign: Campaign, flowRun: FlowRun) {
  return (
    flowRun.status === 'active' &&
    flowRun.step === null &&
    flowRun.pick === null &&
    !flowRun.waitsForSession &&
    campaign.currentSceneNumber !== null &&
    !hooks.has(flowRun.progress?.part ?? null)
  )
}
