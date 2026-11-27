// A small release for the print tests, one field at a time breakable. Shared by their suites.

export const step = (key: string, kind: string, extra: object = {}) => ({
  key,
  kind,
  title: `Title of ${key}`,
  ...extra,
})

export function sceneType(key: string, steps: object[], extra: object = {}) {
  return {
    key,
    name: `Scene ${key}`,
    purpose: 'A purpose.',
    tips: 'A tip.',
    setup: steps,
    play: [],
    closing: [],
    ...extra,
  }
}

/** A release of one Scene Type, two tables and one flow whose road phase draws from `deck`. */
export function miniRelease(
  steps: object[] = [step('ask', 'prompt', { prompt: 'Say something.' })],
) {
  return {
    gameSystem: { name: 'Mini', credits: { license: 'CC BY-SA 4.0' } },
    trackers: [
      { key: 'doom', name: 'Doom', hint: 'Near.', kind: 'clock', segments: 6 },
      { key: 'nerve', name: 'Nerve', hint: 'Held.', kind: 'counter', min: 0, max: 5 },
    ],
    factSlots: [{ key: 'sin', label: 'The Sin' }],
    oracles: {
      likelihood: [{ key: 'yes-no' }],
      tables: [
        {
          key: 'deck',
          name: 'The deck',
          entries: [{ text: 'One', weight: 3, sceneType: 'scene' }, { text: 'Two' }],
        },
        { key: 'luck', name: 'Luck', entries: [{ text: 'Good' }, { text: 'Bad' }] },
      ],
    },
    sceneTypes: [sceneType('scene', steps)],
    flows: [
      {
        key: 'mini',
        name: 'Mini',
        description: 'A question?',
        oracles: ['yes-no', 'luck'],
        trackers: ['doom'],
        phases: [
          { key: 'road', name: 'Road', mode: 'loop', selection: { rule: 'oracle', table: 'deck' } },
          {
            key: 'end',
            name: 'End',
            mode: 'once',
            selection: { rule: 'sequence', sceneTypes: ['scene'] },
          },
        ],
      },
    ],
  }
}

export type MiniRelease = ReturnType<typeof miniRelease>
