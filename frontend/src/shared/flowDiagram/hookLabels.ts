import type { PhaseHook } from './flowDiagram'

/** Plain-English names of the phase hooks, as the manual calls them. */
export const HOOK_LABELS: Record<PhaseHook, string> = {
  sessionOpening: 'Session opening',
  sessionClosing: 'Session closing',
  phaseOpening: 'Phase opening',
  phaseClosing: 'Phase closing',
  sceneOpening: 'Scene opening',
  sceneClosing: 'Scene closing',
  worldTurn: 'World turn',
}
