import type { GameSystem, GameSystemSummary } from './useGameSystems'

// Shared by the Studio tests and stories.

export const published: GameSystemSummary = {
  key: 'noir-detective',
  name: 'Noir detective',
  schemaVersion: 2,
  latestReleaseVersion: 3,
  hasUnpublishedChanges: false,
  libraryChanges: 0,
  updatedAt: '2026-10-14T09:00:00+00:00',
}

export const withChanges: GameSystemSummary = {
  key: 'ash-and-iron',
  name: 'Ash and iron',
  schemaVersion: 2,
  latestReleaseVersion: 1,
  hasUnpublishedChanges: true,
  libraryChanges: 0,
  updatedAt: '2026-10-15T09:00:00+00:00',
}

export const neverPublished: GameSystemSummary = {
  key: 'my-draft',
  name: 'My draft',
  schemaVersion: 2,
  latestReleaseVersion: null,
  hasUnpublishedChanges: true,
  libraryChanges: 0,
  updatedAt: '2026-10-16T09:00:00+00:00',
}

/** A published draft whose imports changed in the library: 1 changed and 2 removed. */
export const withLibraryChanges: GameSystemSummary = {
  ...published,
  key: 'wild-tales',
  name: 'Wild tales',
  libraryChanges: 3,
}

/** A schema version 2 draft that was never published. */
export const draft: GameSystem = {
  key: 'my-draft',
  kind: 'game-system',
  name: 'My draft',
  description: 'A game of my own.',
  credits: { notice: 'Unofficial fan content.', license: 'CC BY 4.0', url: null, badge: null },
  theme: 'noir',
  schemaVersion: 2,
  sectionCounts: { oracleTables: 2, trackers: 1, flows: 0, checks: 3 },
  latestReleaseVersion: null,
  hasUnpublishedChanges: true,
  libraryChanges: 0,
  createdAt: '2026-10-15T09:00:00+00:00',
  updatedAt: '2026-10-16T09:00:00+00:00',
}

/** A published, unchanged schema version 1 GameSystem: no credits, no theme. */
export const legacy: GameSystem = {
  key: 'free-journal',
  kind: 'game-system',
  name: 'Free journal',
  description: null,
  credits: null,
  theme: null,
  schemaVersion: 1,
  sectionCounts: { oracles: 4, flowSteps: 0 },
  latestReleaseVersion: 2,
  hasUnpublishedChanges: false,
  libraryChanges: 0,
  createdAt: '2026-09-01T09:00:00+00:00',
  updatedAt: '2026-09-02T09:00:00+00:00',
}

/** A schema version 2 draft with one library import that changed in its pack. */
export const draftWithLibraryChange: GameSystem = { ...draft, libraryChanges: 1 }

export const validDraft = { valid: true, errors: [], warnings: [] }

/** A published, changed GameSystem: the next publish creates version 4. */
export const published3: GameSystem = {
  ...draft,
  key: 'noir-detective',
  name: 'Noir detective',
  latestReleaseVersion: 3,
}

/** The history of `published3`'s line, newest first; version 2 has notes, version 3 has none but library sources. */
export const releaseHistory = [
  {
    version: 3,
    schemaVersion: 2,
    publishedAt: '2026-10-14T12:00:00+00:00',
    notes: null,
    contentHash: '11d93fc7adb574ab68471c10f7eea5b2c0d4f3a6b8e9d1c2a3b4c5d6e7f8a9b0',
    librarySources: [
      { section: 'oracles', key: 'npc-name', pack: 'fantasy-basics' },
      { section: 'scene-types', key: 'social', pack: 'fantasy-basics' },
      { section: 'tags', key: 'noble', pack: 'wild-tales' },
    ],
  },
  {
    version: 2,
    schemaVersion: 2,
    publishedAt: '2026-10-10T12:00:00+00:00',
    notes: 'Rebalanced the oracles.',
    contentHash: '22d93fc7adb574ab68471c10f7eea5b2c0d4f3a6b8e9d1c2a3b4c5d6e7f8a9b0',
    librarySources: null,
  },
]

/** Version 2 of `published3`'s line, as the read-only view shows it. */
export const releaseTwo = {
  ...releaseHistory[1],
  name: 'Noir detective (old name)',
  sectionCounts: { oracleTables: 1, trackers: 0 },
  outline: {
    oracleTables: [{ key: 'weather', name: 'Weather' }],
    trackers: [],
  },
  /** Version 1 is the release before it; nothing in the two sections changed. */
  changes: {
    previousVersion: 1,
    previousSchemaVersion: 2,
    sections: {
      oracleTables: { added: [], removed: [], changed: [], reordered: false },
      trackers: { added: [], removed: [], changed: [], reordered: false },
    },
  },
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
  errors: [
    { path: 'flows[1].phases[0].selection.table', message: 'unknown oracle table "places"' },
  ],
  warnings: [],
}

/** A valid draft whose warnings are about the flows: two of the first, none of the second. */
export const flowWarnings = {
  valid: true,
  errors: [],
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
    {
      key: 'finesse',
      name: 'Finesse check',
      bands: ['success', 'fail', 'partial'],
      difficulty: { default: 11, min: 5, max: 15 },
    },
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

const sheetStress = { kind: 'sheet', field: 'stress', op: 'add', value: 1 }

/** A choice whose option changes the sheet (its skip option cannot) and a prompt that does (ADR 0042). */
export const stepListSheetEffects = [
  {
    key: 'learned',
    kind: 'choice',
    title: 'Did you learn something?',
    skip: 'no',
    options: [
      { key: 'yes', label: 'Yes', effects: [sheetStress] },
      { key: 'no', label: 'No' },
    ],
  },
  { key: 'cost', kind: 'prompt', title: 'What does it cost?', effects: [sheetStress] },
]

/** A check step offering a set of two checks that share their bands (ADR 0042). */
export const stepListCheckSet = [
  {
    key: 'defy',
    kind: 'check',
    title: 'Defy disaster',
    checks: ['skill', 'finesse'],
    difficulty: 12,
  },
]

/** The pickers when the checks have a Hunger pool: `skill` produces every special result, `luck` none (ADR 0044). */
export const stepPickersHunger = {
  ...stepPickers,
  checks: stepPickers.checks.map((check) => ({
    ...check,
    specials: check.key === 'skill' ? ['critical', 'messyCritical', 'bestialFailure'] : [],
  })),
}

/** A check step on a Hunger check with an outcome for a bestial failure ahead of its bands. */
export const stepListSpecials = [
  {
    key: 'feed',
    kind: 'check',
    title: 'Feed',
    check: 'skill',
    outcomes: [
      { band: 'fail', next: 'end' },
      { special: 'bestialFailure', next: 'end' },
    ],
  },
]

/** A table step that rolls another table in a Chase or a Hideout (ADR 0042). */
export const stepListTableBySceneType = [
  {
    key: 'trouble',
    kind: 'table',
    title: 'Complication',
    table: 'complications',
    tableBySceneType: { chase: 'places', hideout: 'moods' },
    otherwise: { next: 'end' },
  },
]

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

/**
 * The sheet of a draft with declared groups: Stats (2 columns) holds `pow` (spans both) and
 * `dex`, Health holds `hp` and the derived `bonus`, Notes holds nothing. `hp` was published.
 */
export const sheet = {
  version: 7,
  schemaVersion: 2,
  columns: 2,
  groups: [
    { name: 'Stats', columns: 2, span: 1 },
    { name: 'Health', columns: 1, span: 1 },
    { name: 'Notes', columns: 1, span: 1 },
  ],
  fields: [
    { key: 'pow', label: 'Power', group: 'Stats', span: 2, type: 'number', min: 0, max: 18 },
    {
      key: 'hp',
      label: 'Hit points',
      hint: 'Zero is out',
      group: 'Health',
      type: 'track',
      max: 12,
    },
    { key: 'dex', label: 'Dexterity', group: 'Stats', type: 'number', default: 10 },
    { key: 'bonus', label: 'Bonus', group: 'Health', type: 'derived', formula: '{pow} + 1' },
  ],
  trackers: [{ key: 'heat', label: 'Heat', max: 10 }],
  publishedFieldKeys: ['hp'],
  pointBudgets: [],
  gear: [],
  currency: null,
}

/** The sheet with a conditions field, Physical, marked from Hurt and Tired. */
export const sheetWithConditions = {
  ...sheet,
  version: 9,
  fields: [
    ...sheet.fields,
    {
      key: 'physical',
      label: 'Physical',
      group: 'Health',
      type: 'conditions',
      options: [
        { key: 'hurt', label: 'Hurt' },
        { key: 'tired', label: 'Tired' },
      ],
    },
  ],
}

/** The sheet with two point budgets: Power on its own, Dexterity on its own. */
export const sheetWithPointBudgets = {
  ...sheet,
  version: 11,
  pointBudgets: [
    { key: 'attributes', label: 'Attribute points', total: 12, fields: ['pow'], min: 1, max: 5 },
    { key: 'skills', label: 'Skill points', total: 8, fields: ['dex'], min: null, max: 3 },
  ],
}

/** The sheet of a legacy budget with no label: it reads as the budget `points`. */
export const sheetWithLegacyBudget = {
  ...sheet,
  version: 12,
  pointBudgets: [{ key: 'points', label: null, total: 30, fields: ['pow'], min: null, max: null }],
}

/** A sheet without declared groups: Play lists consecutive fields of one group together. */
export const sheetUngrouped = {
  version: 4,
  schemaVersion: 2,
  columns: 1,
  groups: null,
  fields: [
    { key: 'pow', label: 'Power', group: 'Stats', type: 'number' },
    { key: 'dex', label: 'Dexterity', group: 'Stats', type: 'number' },
    { key: 'notes', label: 'Notes', type: 'text', multiline: true },
    { key: 'hp', label: 'Hit points', group: 'Health', type: 'track', max: 12 },
  ],
  trackers: [],
  publishedFieldKeys: [],
  pointBudgets: [],
  gear: [],
  currency: null,
}

/** A schema version 1 draft's sheet: no fields. */
export const sheetV1 = { ...sheetUngrouped, schemaVersion: 1, fields: [] }

/**
 * The checks of a draft: `skill` has a difficulty and a band bounded by it and is used by a check
 * step; `luck` was stored before the editor, with a tracker bound and no difficulty.
 */
export const checks = {
  version: 5,
  schemaVersion: 2,
  checks: [
    {
      key: 'skill',
      label: 'Skill check',
      hint: 'Roll low, add your edge',
      dice: '1d10',
      modifier: '{pow}',
      difficulty: { label: 'Difficulty', default: 13, min: 9, max: 29 },
      bands: [
        { key: 'fail', label: 'Fail', upTo: { formula: '{check:difficulty}-1' } },
        { key: 'win', label: 'Win' },
      ],
    },
    {
      key: 'luck',
      label: 'Luck',
      dice: 'd%',
      bands: [
        { key: 'bad', label: 'Bad', upTo: { tracker: 'heat' } },
        { key: 'mid', label: 'Middling', upTo: 40 },
        { key: 'good', label: 'Good' },
      ],
    },
  ],
  fields: [
    { key: 'pow', label: 'Power', type: 'number', max: null },
    { key: 'hp', label: 'Hit points', type: 'track', max: 12 },
  ],
  trackers: [{ key: 'heat', label: 'Heat', initial: 0 }],
  conditions: [],
  usages: { skill: ['sceneTypes[0].play[0].check'], luck: [] },
}

/** A schema version 1 draft's checks: none, and every command refused. */
export const checksV1 = {
  version: 3,
  schemaVersion: 1,
  checks: [],
  fields: [],
  trackers: [],
  conditions: [],
  usages: {},
}
