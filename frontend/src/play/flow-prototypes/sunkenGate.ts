import type { FlowInput, FlowSession } from './flowRun'

// The static mock session both flow prototypes play (Storybook only, never imported by
// the app). Every result is scripted, so a session plays the same way every time.

/**
 * Scene 1 — The Sunken Gate: every step type, a branch on the oracle's answer and a
 * branch on the player's choice.
 *
 * `gateGuarded` scripts the oracle's answer, so a story can play either branch.
 */
export function sunkenGateSession({ gateGuarded = false } = {}): FlowSession {
  return {
    sessionNumber: 1,
    sceneNumber: 1,
    sceneTitle: 'The Sunken Gate',
    startStepId: 'set-scene',
    steps: {
      'set-scene': {
        id: 'set-scene',
        kind: 'prompt',
        title: 'Set the scene',
        text: 'The tide pulls back from the Sunken Gate. Describe where your character stands and what they see.',
        placeholder: 'Wet stone, a rusted portcullis…',
        next: 'ask-guard',
      },
      'ask-guard': {
        id: 'ask-guard',
        kind: 'oracle',
        title: 'Ask the oracle',
        question: 'Is the gate guarded?',
        oracleName: 'Fate question',
        likelihood: 'unlikely',
        likelihoodLabel: 'Unlikely',
        scripted: gateGuarded
          ? { answer: 'yes', roll: 21, sides: 100, effectiveTarget: 25, chaosFactor: 5 }
          : { answer: 'no', roll: 64, sides: 100, effectiveTarget: 25, chaosFactor: 5 },
        next: { yes: 'face-guard', no: 'choose-way' },
      },
      'face-guard': {
        id: 'face-guard',
        kind: 'prompt',
        title: 'Describe the guard',
        text: 'Someone keeps watch at the gate. Describe them, and how your character avoids their eye.',
        placeholder: 'A drowned knight, still at their post…',
        next: 'choose-way',
      },
      'choose-way': {
        id: 'choose-way',
        kind: 'choice',
        title: 'Choose a way in',
        text: 'How does your character get past the gate?',
        options: [
          { id: 'swim', label: 'Swim under the flooded arch', next: 'swim-roll' },
          { id: 'climb', label: 'Climb the broken wall', next: 'climb-roll' },
        ],
      },
      'swim-roll': {
        id: 'swim-roll',
        kind: 'roll',
        title: 'Roll to swim',
        text: 'Hold your breath through the flooded arch.',
        expression: '1d20+2',
        scripted: {
          total: 16,
          groups: [
            { notation: '1d20', sides: 20, dice: [{ value: 14, kept: true }], subtotal: 14 },
          ],
        },
        next: 'close-scene',
      },
      'climb-roll': {
        id: 'climb-roll',
        kind: 'roll',
        title: 'Roll to climb',
        text: 'Find handholds on the slick, broken wall.',
        expression: '2d6+1',
        scripted: {
          total: 8,
          groups: [
            {
              notation: '2d6',
              sides: 6,
              dice: [
                { value: 3, kept: true },
                { value: 4, kept: true },
              ],
              subtotal: 7,
            },
          ],
        },
        next: 'close-scene',
      },
      'close-scene': {
        id: 'close-scene',
        kind: 'journal',
        title: 'Write a journal entry',
        text: 'Close the scene: what does your character find beyond the gate?',
        placeholder: 'A stair winding down into the dark…',
        next: null,
      },
    },
  }
}

/** Plays the session (no guard) to the swim roll: three steps answered, a roll to make. */
export const midSessionInputs: FlowInput[] = [
  { kind: 'text', text: 'Wet stone, a rusted portcullis half out of the water.' },
  { kind: 'ask' },
  { kind: 'choose', optionId: 'swim' },
]

/** Plays the whole session (no guard, swimming), to the end of the scene. */
export const finishedSessionInputs: FlowInput[] = [
  ...midSessionInputs,
  { kind: 'roll' },
  { kind: 'text', text: 'Beyond the arch, a stair winds down into the dark.' },
]
