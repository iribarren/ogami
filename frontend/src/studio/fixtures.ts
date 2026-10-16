import type { GameSystem, GameSystemSummary } from './useGameSystems'

// Shared by the Studio tests and stories.

export const published: GameSystemSummary = {
  key: 'noir-detective',
  name: 'Noir detective',
  schemaVersion: 2,
  latestReleaseVersion: 3,
  hasUnpublishedChanges: false,
  updatedAt: '2026-10-14T09:00:00+00:00',
}

export const withChanges: GameSystemSummary = {
  key: 'ash-and-iron',
  name: 'Ash and iron',
  schemaVersion: 2,
  latestReleaseVersion: 1,
  hasUnpublishedChanges: true,
  updatedAt: '2026-10-15T09:00:00+00:00',
}

export const neverPublished: GameSystemSummary = {
  key: 'my-draft',
  name: 'My draft',
  schemaVersion: 2,
  latestReleaseVersion: null,
  hasUnpublishedChanges: true,
  updatedAt: '2026-10-16T09:00:00+00:00',
}

/** A schema version 2 draft that was never published. */
export const draft: GameSystem = {
  key: 'my-draft',
  name: 'My draft',
  description: 'A game of my own.',
  credits: { notice: 'Unofficial fan content.', license: 'CC BY 4.0', url: null },
  theme: 'noir',
  schemaVersion: 2,
  sectionCounts: { oracleTables: 2, trackers: 1, flows: 0, checks: 3 },
  latestReleaseVersion: null,
  hasUnpublishedChanges: true,
  createdAt: '2026-10-15T09:00:00+00:00',
  updatedAt: '2026-10-16T09:00:00+00:00',
}

/** A published, unchanged schema version 1 GameSystem: no credits, no theme. */
export const legacy: GameSystem = {
  key: 'free-journal',
  name: 'Free journal',
  description: null,
  credits: null,
  theme: null,
  schemaVersion: 1,
  sectionCounts: { oracles: 4, flowSteps: 0 },
  latestReleaseVersion: 2,
  hasUnpublishedChanges: false,
  createdAt: '2026-09-01T09:00:00+00:00',
  updatedAt: '2026-09-02T09:00:00+00:00',
}

export const validDraft = { valid: true, error: null, warnings: [] }

/** A published, changed GameSystem: the next publish creates version 4. */
export const published3: GameSystem = {
  ...draft,
  key: 'noir-detective',
  name: 'Noir detective',
  latestReleaseVersion: 3,
}

/** The history of `published3`'s line, newest first; version 2 has notes, version 3 has none. */
export const releaseHistory = [
  {
    version: 3,
    schemaVersion: 2,
    publishedAt: '2026-10-14T12:00:00+00:00',
    notes: null,
    contentHash: '11d93fc7adb574ab68471c10f7eea5b2c0d4f3a6b8e9d1c2a3b4c5d6e7f8a9b0',
  },
  {
    version: 2,
    schemaVersion: 2,
    publishedAt: '2026-10-10T12:00:00+00:00',
    notes: 'Rebalanced the oracles.',
    contentHash: '22d93fc7adb574ab68471c10f7eea5b2c0d4f3a6b8e9d1c2a3b4c5d6e7f8a9b0',
  },
]

/** Version 2 of `published3`'s line, as the read-only view shows it. */
export const releaseTwo = {
  ...releaseHistory[1],
  name: 'Noir detective (old name)',
  sectionCounts: { oracleTables: 1, trackers: 0 },
}

/** The oracles of `draft` (version 7): one ranged and one weighted table, one in use, and a likelihood oracle. */
export const oracles = {
  version: 7,
  schemaVersion: 2,
  tables: [
    {
      definition: {
        key: 'weather',
        name: 'Weather',
        dice: '1d6',
        source: 'srd',
        entries: [
          {
            min: 1,
            max: 4,
            text: 'Clear',
            key: 'clear',
            sceneType: 'calm',
            effects: [{ kind: 'x' }],
          },
          { min: 5, max: 6, text: 'Storm', table: 'storm-kind' },
        ],
      },
      usedBy: ['sceneTypes[1].play[0]'],
    },
    {
      definition: {
        key: 'storm-kind',
        name: 'Storm kind',
        entries: [{ weight: 2, text: 'Rain' }, { text: 'Hail' }, { weight: 1, text: 'Gale' }],
      },
      usedBy: [],
    },
  ],
  likelihood: [
    {
      definition: {
        key: 'fate',
        name: 'Fate question',
        sides: 100,
        levels: [
          { key: 'even', label: '50/50', target: 50 },
          { key: 'likely', label: 'Likely', target: 65 },
        ],
      },
      usedBy: ['flows[0].phases[0].steps[1]'],
    },
  ],
}

/** A schema version 1 draft's oracles: tables only, no entry keys. */
export const oraclesV1 = {
  version: 2,
  schemaVersion: 1,
  tables: [
    {
      definition: {
        key: 'weather',
        name: 'Weather',
        dice: '1d6',
        entries: [{ min: 1, max: 6, text: 'Clear' }],
      },
      usedBy: [],
    },
  ],
  likelihood: [],
}

/** What rolling `weather` can answer: the storm entry nests `storm-kind`. */
export const stormRoll = {
  table: 'weather',
  steps: [
    {
      tableKey: 'weather',
      tableName: 'Weather',
      dice: '1d6',
      total: 6,
      text: 'Storm',
      nestedTableKey: 'storm-kind',
    },
    {
      tableKey: 'storm-kind',
      tableName: 'Storm kind',
      dice: '1d4',
      total: 2,
      text: 'Hail',
      nestedTableKey: null,
    },
  ],
}

/** `oracles` with `fate` fully set: chaos (and its tracker), exceptional bands and a random event table. */
export const oraclesWithChaos = {
  ...oracles,
  likelihood: [
    {
      ...oracles.likelihood[0],
      definition: {
        ...oracles.likelihood[0]?.definition,
        chaos: { min: 1, max: 9, neutral: 5, shiftPerPoint: 5, tracker: 'chaos' },
        exceptionalPercent: 20,
        randomEvent: { table: 'storm-kind' },
      },
    },
  ],
}

/** A schema version 1 draft with a likelihood oracle: no random event, no chaos tracker. */
export const oraclesV1Likelihood = {
  ...oraclesV1,
  likelihood: [
    {
      definition: {
        key: 'fate',
        name: 'Fate question',
        sides: 100,
        levels: [{ key: 'even', label: '50/50', target: 50 }],
        chaos: { min: 1, max: 9, neutral: 5, shiftPerPoint: 5 },
      },
      usedBy: [],
    },
  ],
}

/** Asking `fate` at chaos factor 7: a yes on a double, which signals the random event table. */
export const fateAnswer = {
  answer: {
    answer: 'exceptional_yes',
    roll: 33,
    sides: 100,
    effectiveTarget: 75,
    likelihood: 'likely',
    likelihoodLabel: 'Likely',
    chaosFactor: 7,
    randomEvent: 'storm-kind',
  },
  randomEvent: {
    table: 'storm-kind',
    steps: [
      {
        tableKey: 'storm-kind',
        tableName: 'Storm kind',
        dice: '1d4',
        total: 2,
        text: 'Hail',
        nestedTableKey: null,
      },
    ],
  },
}

const noCatalog = { tables: [], likelihood: [], checks: [], fields: [] }

/**
 * The narrative of `draft` (version 5): a counter with levels that a flow uses, a clock, a
 * tracker of a kind the editor does not know, two tags and two fact slots.
 */
export const narrative = {
  version: 5,
  schemaVersion: 2,
  trackers: [
    {
      key: 'heat',
      definition: {
        key: 'heat',
        name: 'Heat',
        hint: 'How closely the law is looking',
        kind: 'counter',
        min: 0,
        max: 10,
        initial: 2,
        levels: [{ upTo: 3, label: 'Quiet' }, { label: 'Burning' }],
      },
      usedBy: ['flows[0].trackers'],
    },
    {
      key: 'alarm',
      definition: { key: 'alarm', name: 'Alarm', kind: 'clock', segments: 6 },
      usedBy: [],
    },
    {
      key: 'mood',
      definition: { key: 'mood', name: 'Mood', kind: 'gauge' },
      usedBy: [],
    },
  ],
  tags: [
    {
      key: 'gang',
      definition: { key: 'gang', label: 'Gang', hint: 'Who runs with whom' },
      usedBy: ['sceneTypes[0].play[1]'],
    },
    { key: 'informer', definition: { key: 'informer', label: 'Informer' }, usedBy: [] },
  ],
  factSlots: [
    {
      key: 'target',
      definition: { key: 'target', label: 'Target', type: 'npc' },
      usedBy: [],
    },
    {
      key: 'client',
      definition: { key: 'client', label: 'Client', type: 'text', hint: 'Who pays' },
      usedBy: ['flows[0].phases[0].sceneOpening[0]'],
    },
  ],
  sceneTypes: [],
  flows: [],
  catalog: noCatalog,
}

/** A schema version 1 draft's narrative: no sections. */
export const narrativeV1 = {
  ...narrative,
  schemaVersion: 1,
  trackers: [],
  tags: [],
  factSlots: [],
}

/** What the step forms pick from: the draft's tables, trackers and tags. */
export const stepPickers = {
  tables: [
    { key: 'places', name: 'Places' },
    { key: 'moods', name: 'Moods' },
  ],
  trackers: [
    { key: 'heat', name: 'Heat' },
    { key: 'alarm', name: 'Alarm' },
  ],
  tags: [{ key: 'gang', name: 'Gang' }],
}

const intro = {
  key: 'intro',
  kind: 'prompt',
  title: 'Set the scene',
  prompt: 'What is the place like?',
  mandatory: true,
  inspiration: ['places'],
}
const who = { key: 'who', kind: 'character', title: 'Who is in on it?', next: 'end' }
const heatRoll = {
  key: 'heat-roll',
  kind: 'roll',
  title: 'How hot is it?',
  dice: '1d6',
  bands: [
    { upTo: 2, effects: [{ kind: 'tracker', tracker: 'heat', op: 'add', value: 1 }] },
    { upTo: { tracker: 'alarm' }, next: 'end' },
    {},
  ],
}
const crowd = {
  key: 'crowd',
  kind: 'condition',
  title: 'Is the gang here?',
  count: { of: 'npc', tags: ['gang'] },
  bands: [{ upTo: 0 }, { next: 'end' }],
}

/** Stored steps of every kind the step editor edits. */
export const stepList = [intro, heatRoll, crowd, who]

/** A step list with a kind the editor does not edit yet and a step it cannot read. */
export const stepListWithOtherKinds = [
  intro,
  {
    key: 'ask',
    kind: 'oracle',
    title: 'Does it go wrong?',
    oracle: 'likelihood',
    likelihood: 'likely',
  },
  { key: 'odd', kind: 'prompt', title: 'Odd', inspiration: 'places' },
]

/** `next` pointing at the same and at an earlier step. */
export const stepListBackward = [
  { key: 'first', kind: 'character', title: 'First' },
  { key: 'second', kind: 'character', title: 'Second', next: 'first' },
  {
    key: 'third',
    kind: 'roll',
    title: 'Third',
    dice: '1d6',
    bands: [{ upTo: 3, next: 'third' }, {}],
  },
]
