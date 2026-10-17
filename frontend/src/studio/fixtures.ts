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

/** A Scene Type with steps in all three lists, an oracle shortcut, tips and a field the editor does not know. */
export const ambush = {
  key: 'ambush',
  name: 'Ambush',
  purpose: 'Something goes wrong on the street.',
  tips: 'Keep it short.',
  oracles: ['fate', 'complications'],
  setup: [{ key: 'arrive', kind: 'prompt', title: 'Arrive', prompt: 'Where are you?' }],
  play: [
    {
      key: 'ask',
      kind: 'oracle',
      title: 'Is it a trap?',
      oracle: 'fate',
      likelihood: 'likely',
      branches: { yes: { next: 'react' } },
    },
    { key: 'react', kind: 'prompt', title: 'React' },
  ],
  closing: [{ key: 'aftermath', kind: 'prompt', title: 'Aftermath' }],
  futureField: { nested: true },
}

/**
 * The narrative with three Scene Types: `ambush` (used by a flow), a legacy one with no tips and
 * no steps, and one of a shape the editor cannot read; and the catalog its pickers need.
 */
export const narrativeScenes = {
  ...narrative,
  sceneTypes: [
    { key: 'ambush', definition: ambush, usedBy: ['flows[0].phases[0].selection.sceneTypes[0]'] },
    {
      key: 'quiet',
      definition: {
        key: 'quiet',
        name: 'Quiet',
        purpose: 'Catch a breath.',
        oracles: [],
        setup: [],
        play: [],
        closing: [],
      },
      usedBy: [],
    },
    {
      key: 'odd',
      definition: {
        key: 'odd',
        name: 'Odd',
        purpose: 42,
        oracles: [],
        setup: [],
        play: [],
        closing: [],
      },
      usedBy: [],
    },
  ],
  catalog: {
    ...noCatalog,
    tables: [
      { key: 'places', name: 'Places', entries: ['market', 'docks'] },
      { key: 'complications', name: 'Complications', entries: ['patrol', 'lucky-break'] },
    ],
    likelihood: [{ key: 'fate', name: 'Fate', levels: ['unlikely', 'even', 'likely'] }],
  },
}

/**
 * The narrative with the three Scene Types of `narrativeScenes` and three flows: `heist` (the
 * default, two phases), `side-job` (one phase, listed as used) and one of a shape the editor
 * cannot read.
 */
export const narrativeFlows = {
  ...narrativeScenes,
  flows: [
    {
      key: 'heist',
      definition: {
        key: 'heist',
        name: 'Heist',
        default: true,
        defaultView: 'focus',
        oracles: ['fate'],
        trackers: [],
        phases: [
          {
            key: 'setup',
            name: 'Setup',
            mode: 'once',
            selection: { rule: 'sequence', sceneTypes: ['ambush'] },
          },
          {
            key: 'job',
            name: 'The job',
            mode: 'loop',
            selection: { rule: 'player', sceneTypes: ['ambush', 'quiet'] },
          },
        ],
      },
      usedBy: [],
    },
    {
      key: 'side-job',
      definition: {
        key: 'side-job',
        name: 'Side job',
        defaultView: 'journal',
        oracles: [],
        trackers: [],
        phases: [
          {
            key: 'main',
            name: 'Main',
            mode: 'once',
            selection: { rule: 'oracle', table: 'places' },
          },
        ],
      },
      usedBy: ['sceneTypes[0].play[1]'],
    },
    {
      key: 'odd-flow',
      definition: { key: 'odd-flow', name: 'Odd flow', defaultView: 'focus', phases: 'none' },
      usedBy: [],
    },
  ],
}

/** An invalid draft whose one error is about the second flow (no warnings; a test adds its own). */
export const flowIssues = {
  valid: false,
  error: { message: 'flows[1].phases[0].selection.table: unknown oracle table "places"' },
  warnings: [],
}

/** A valid draft whose warnings are about the flows: two of the first, none of the second. */
export const flowWarnings = {
  valid: true,
  error: null,
  warnings: [
    'flows[0].phases[1]: no step ever ends the phase',
    'flows[0].oracles[0]: is not used by any step',
    'sceneTypes[1]: is never selected',
  ],
}

/** What the step forms pick from: the draft's tables (with entries), likelihood oracles (with levels), checks (with bands and difficulty range), trackers, tags, Scene Types, fact slots and sheet fields. */
export const stepPickers = {
  tables: [
    { key: 'places', name: 'Places', entries: ['market', 'docks'] },
    { key: 'moods', name: 'Moods', entries: ['calm', 'tense'] },
    { key: 'complications', name: 'Complications', entries: ['patrol', 'lucky-break'] },
  ],
  likelihood: [{ key: 'fate', name: 'Fate', levels: ['unlikely', 'even', 'likely'] }],
  checks: [
    {
      key: 'skill',
      name: 'Skill check',
      bands: ['fail', 'partial', 'success'],
      difficulty: { default: 13, min: 9, max: 29 },
    },
    { key: 'luck', name: 'Luck', bands: ['lose', 'win'], difficulty: null },
  ],
  trackers: [
    { key: 'heat', name: 'Heat' },
    { key: 'alarm', name: 'Alarm' },
  ],
  tags: [{ key: 'gang', name: 'Gang' }],
  sceneTypes: [
    { key: 'chase', name: 'Chase' },
    { key: 'hideout', name: 'Hideout' },
  ],
  factSlots: [
    { key: 'client', name: 'Client', type: 'text' },
    { key: 'target', name: 'Target', type: 'npc' },
  ],
  sheetFields: [
    { key: 'hands', name: 'Hands' },
    { key: 'stress', name: 'Stress' },
  ],
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

const ask = {
  key: 'ask',
  kind: 'oracle',
  title: 'Does it go wrong?',
  oracle: 'fate',
  likelihood: 'likely',
  branches: {
    yes: { next: 'event' },
    exceptionalNo: { effects: [{ kind: 'sceneTitle', title: 'Luck' }] },
  },
}
const event = {
  key: 'event',
  kind: 'table',
  title: 'What happens?',
  table: 'complications',
  branches: [
    { entry: 'patrol', next: 'which' },
    { entry: 'lucky-break', effects: [{ kind: 'sceneTitle', title: 'Luck' }] },
  ],
  otherwise: { next: 'end' },
}
const which = {
  key: 'which',
  kind: 'choice',
  title: 'Which way?',
  mandatory: true,
  options: [
    { key: 'left', label: 'Left', next: 'end' },
    { key: 'right', label: 'Right' },
  ],
  skip: 'left',
}

/** One step each of the kinds with branches: an oracle, a table and a choice. */
export const stepListBranching = [ask, event, which]

const find = {
  key: 'find',
  kind: 'pick',
  title: 'Who turns up?',
  of: 'npc',
  mode: 'random',
  tags: ['gang'],
  count: { min: 1, max: 3 },
  branches: {
    found: { next: 'end' },
    none: { effects: [{ kind: 'sceneTitle', title: 'Nobody' }] },
  },
}
const lock = {
  key: 'lock',
  kind: 'check',
  title: 'Pick the lock',
  check: 'skill',
  difficulty: 15,
  outcomes: [
    { band: 'fail', next: 'end' },
    { band: 'success', effects: [{ kind: 'sheet', field: 'stress', op: 'add', value: 1 }] },
  ],
}

/** A pick step and a check step. */
export const stepListPickCheck = [find, lock]

/** A step list with a kind the editor does not edit yet and a step it cannot read. */
export const stepListWithOtherKinds = [
  intro,
  { key: 'ask', kind: 'pick', title: 'Who is in?', of: 'npc', mode: 'one' },
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
