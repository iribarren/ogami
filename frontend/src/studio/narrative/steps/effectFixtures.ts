import { stepPickers } from '../../fixtures'

/** What the effect forms pick from: the step pickers, Scene Types, fact slots and sheet fields included. */
export const effectPickers = stepPickers

/** One stored effect of every kind, each with its optional fields and a field the editor does not know. */
export const every = [
  { kind: 'tracker', tracker: 'heat', op: 'add', value: 2 },
  { kind: 'tracker', tracker: 'alarm', op: 'set', value: { tracker: 'heat' }, note: 'x' },
  { kind: 'tracker', tracker: 'heat', op: 'add', value: { formula: 'heat + 1' } },
  { kind: 'nextScene', sceneType: 'chase' },
  { kind: 'switchSceneType', sceneType: 'hideout' },
  { kind: 'endPhase' },
  { kind: 'sceneTitle', title: 'Dawn', mood: 'calm' },
  { kind: 'createNpc', name: 'Vera', agenda: 'Wants out', tags: ['gang'] },
  { kind: 'createThread', name: 'The debt', tags: ['gang'] },
  { kind: 'closeThread' },
  { kind: 'fillFact', slot: 'client', text: 'A fence' },
  { kind: 'sheet', field: 'hands', op: 'subtract', value: { dice: '1d4' } },
  { kind: 'sheet', field: 'stress', op: 'set', value: { formula: 'hands * 2' } },
]
