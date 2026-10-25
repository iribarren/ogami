import type { JournalEntry } from './useJournal'

// Shared by the journal tests and stories; they belong to `lostMineCampaign`.

export const gateNote: JournalEntry = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001',
  sessionNumber: 1,
  sceneNumber: 1,
  recordedAt: '2026-10-05T12:11:00+00:00',
  kind: 'note',
  flowStep: null,
  content: { kind: 'note', text: 'The gate is open.\nNobody guards it.', attachments: [] },
}

export const keepThreeRoll: JournalEntry = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7002',
  sessionNumber: 1,
  sceneNumber: 1,
  recordedAt: '2026-10-05T12:12:00+00:00',
  kind: 'roll',
  flowStep: null,
  content: {
    kind: 'roll',
    expression: '4d6kh3+2',
    total: 17,
    groups: [
      {
        notation: '4d6kh3',
        sides: 6,
        dice: [
          { value: 6, kept: true },
          { value: 1, kept: false },
          { value: 5, kept: true },
          { value: 4, kept: true },
        ],
        subtotal: 15,
      },
    ],
  },
}

export const stormyWeather: JournalEntry = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7003',
  sessionNumber: 2,
  sceneNumber: 1,
  recordedAt: '2026-10-06T12:06:00+00:00',
  kind: 'oracle-table',
  flowStep: null,
  content: {
    kind: 'oracle-table',
    oracleKey: 'weather',
    oracleName: 'Weather',
    forEntry: null,
    steps: [
      {
        tableKey: 'weather',
        tableName: 'Weather',
        dice: '1d6',
        total: 6,
        text: 'A storm',
        nestedTableKey: 'storm-kind',
        sceneType: null,
      },
      {
        tableKey: 'storm-kind',
        tableName: 'Storm kind',
        dice: '1d4',
        total: 2,
        text: 'Hail',
        nestedTableKey: null,
        sceneType: null,
      },
    ],
  },
}

export const lockedDoorAnswer: JournalEntry = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7004',
  sessionNumber: 2,
  sceneNumber: 1,
  recordedAt: '2026-10-06T12:07:00+00:00',
  kind: 'likelihood',
  flowStep: null,
  content: {
    kind: 'likelihood',
    oracleKey: 'fate',
    oracleName: 'Fate question',
    question: 'Is the door locked?',
    answer: 'exceptional_yes',
    roll: 4,
    sides: 100,
    effectiveTarget: 75,
    likelihood: 'likely',
    likelihoodLabel: 'Likely',
    chaosFactor: 7,
    randomEvent: null,
  },
}

/** A likelihood answer without a question or chaos factor. */
export const plainAnswer: JournalEntry = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7005',
  sessionNumber: 2,
  sceneNumber: 1,
  recordedAt: '2026-10-06T12:08:00+00:00',
  kind: 'likelihood',
  flowStep: null,
  content: {
    kind: 'likelihood',
    oracleKey: 'yes-no',
    oracleName: 'Yes/no question',
    question: null,
    answer: 'no',
    roll: 81,
    sides: 100,
    effectiveTarget: 50,
    likelihood: 'even',
    likelihoodLabel: 'Even odds',
    chaosFactor: null,
    randomEvent: null,
  },
}

/** A fate question answered with a double within the chaos factor: its random event rolls "weather". */
export const fateDouble: JournalEntry = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7006',
  sessionNumber: 2,
  sceneNumber: 1,
  recordedAt: '2026-10-06T12:09:00+00:00',
  kind: 'likelihood',
  flowStep: null,
  content: {
    kind: 'likelihood',
    oracleKey: 'fate',
    oracleName: 'Fate question',
    question: 'Does the guard notice?',
    answer: 'yes',
    roll: 33,
    sides: 100,
    effectiveTarget: 75,
    likelihood: 'likely',
    likelihoodLabel: 'Likely',
    chaosFactor: 7,
    randomEvent: 'weather',
  },
}

/** The roll of the random event of `fateDouble`: the weather table, tied to that question. */
export const fateDoubleEvent: JournalEntry = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7050',
  sessionNumber: 2,
  sceneNumber: 1,
  recordedAt: '2026-10-06T12:10:00+00:00',
  kind: 'oracle-table',
  flowStep: null,
  content: {
    kind: 'oracle-table',
    oracleKey: 'weather',
    oracleName: 'Weather',
    forEntry: fateDouble.id,
    steps: [
      {
        tableKey: 'weather',
        tableName: 'Weather',
        dice: '1d6',
        total: 3,
        text: 'Dense fog',
        nestedTableKey: null,
        sceneType: null,
      },
    ],
  },
}

/** A "Skill check" by Vi against a difficulty, from a sheet roll: 7 + 6 (Hands 4 + Edge 2) + 2 = 15 is a success. */
export const skillCheckEntry: JournalEntry = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7007',
  sessionNumber: 2,
  sceneNumber: 1,
  recordedAt: '2026-10-06T12:10:00+00:00',
  kind: 'check',
  flowStep: null,
  content: {
    kind: 'check',
    characterId: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9001',
    characterName: 'Vi',
    checkKey: 'skill',
    checkLabel: 'Skill check',
    expression: '1d10',
    groups: [{ notation: '1d10', sides: 10, dice: [{ value: 7, kept: true }], subtotal: 7 }],
    diceTotal: 7,
    modifier: 6,
    situational: 2,
    difficulty: 13,
    total: 15,
    bandKey: 'success',
    bandLabel: 'Success',
    hint: 'A stat against a Difficulty Value.',
    modifierFormula: '{hands} + {edge}',
    modifierTerms: [
      { reference: '{hands}', label: 'Hands', value: 4 },
      { reference: '{edge}', label: 'Edge', value: 2 },
    ],
    pool: null,
  },
}

/** A "Luck" check from a flow step: no modifiers and no difficulty. */
export const luckCheckEntry: JournalEntry = {
  ...skillCheckEntry,
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7008',
  flowStep: { key: 'tempt-fate', title: 'Tempt fate', prompt: null },
  content: {
    kind: 'check',
    characterId: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9001',
    characterName: 'Vi',
    checkKey: 'luck',
    checkLabel: 'Luck',
    expression: 'd%',
    groups: [{ notation: '1d100', sides: 100, dice: [{ value: 12, kept: true }], subtotal: 12 }],
    diceTotal: 12,
    modifier: 0,
    situational: 0,
    difficulty: null,
    total: 12,
    bandKey: 'bad',
    bandLabel: 'Bad luck',
    hint: null,
    modifierFormula: null,
    modifierTerms: [],
    pool: null,
  },
}

/** A pool check: Agility 2 + Stealth 1 - Physical 1 = 2 dice, one extra die, so 3d6 with a success on 5+. */
export const sneakCheckEntry: JournalEntry = {
  ...skillCheckEntry,
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7009',
  flowStep: null,
  content: {
    kind: 'check',
    characterId: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9001',
    characterName: 'Vi',
    checkKey: 'sneak',
    checkLabel: 'Sneak',
    expression: '3d6',
    groups: [],
    diceTotal: 2,
    modifier: 0,
    situational: 1,
    difficulty: null,
    total: 2,
    bandKey: 'hit',
    bandLabel: 'Hit',
    hint: 'Count the sixes and fives.',
    modifierFormula: null,
    modifierTerms: [],
    pool: {
      count: 2,
      countFormula: '{agility} + {stealth} - {physical}',
      terms: [
        { reference: '{agility}', label: 'Agility', value: 2 },
        { reference: '{stealth}', label: 'Stealth', value: 1 },
        { reference: '{physical}', label: 'Physical', value: 1 },
      ],
      size: 3,
      sides: 6,
      successAt: 5,
      dice: [
        { group: 'pool', face: 6, success: true },
        { group: 'pool', face: 2, success: false },
        { group: 'pool', face: 5, success: true },
      ],
      successes: 2,
      push: null,
    },
  },
}

/** The journal of `lostMineCampaign`: every kind, in recorded order. */
export const lostMineJournal: JournalEntry[] = [
  gateNote,
  keepThreeRoll,
  stormyWeather,
  lockedDoorAnswer,
  plainAnswer,
]

/** An entry as the API answers a record request, in session 2, scene 1. */
export function recordedEntry(
  content: JournalEntry['content'],
  id = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7099',
): JournalEntry {
  return {
    id,
    sessionNumber: 2,
    sceneNumber: 1,
    recordedAt: '2026-10-06T12:30:00+00:00',
    kind: content.kind,
    content,
    flowStep: null,
  }
}

export const chosenWay: JournalEntry = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7006',
  sessionNumber: 1,
  sceneNumber: 1,
  recordedAt: '2026-10-05T12:16:00+00:00',
  kind: 'choice',
  content: { kind: 'choice', question: 'Which way?', optionKey: 'right', label: 'Go right' },
  flowStep: { key: 'fork', title: 'Which way?', prompt: null },
}

/**
 * A roll on the table "Scene shift" whose entry offers the Scene Type `sceneType` (none when
 * null), in session 2, scene 1 unless `where` says otherwise.
 */
export function sceneShiftRoll(
  sceneType: string | null,
  where: Pick<JournalEntry, 'sessionNumber' | 'sceneNumber'> = { sessionNumber: 2, sceneNumber: 1 },
): JournalEntry {
  return {
    ...recordedEntry({
      kind: 'oracle-table',
      oracleKey: 'scene-shift',
      oracleName: 'Scene shift',
      forEntry: null,
      steps: [
        {
          tableKey: 'scene-shift',
          tableName: 'Scene shift',
          dice: '1d6',
          total: 6,
          text: 'Guards arrive',
          nestedTableKey: null,
          sceneType,
        },
      ],
    }),
    ...where,
  }
}

/** A pick step that found two NPCs, tagged "leader". */
export const pickedTwo: JournalEntry = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7007',
  sessionNumber: 1,
  sceneNumber: 1,
  recordedAt: '2026-10-05T12:17:00+00:00',
  kind: 'pick',
  content: {
    kind: 'pick',
    of: 'npc',
    tags: ['leader'],
    picked: [
      { id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001', name: 'Mira Vane' },
      { id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7002', name: 'Brann' },
    ],
  },
  flowStep: { key: 'who', title: 'Who shows up?', prompt: null },
}

/** A pick step of Threads that matched nothing. */
export const pickedNothing: JournalEntry = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7008',
  sessionNumber: 1,
  sceneNumber: 1,
  recordedAt: '2026-10-05T12:18:00+00:00',
  kind: 'pick',
  content: { kind: 'pick', of: 'thread', tags: [], picked: [] },
  flowStep: { key: 'draw-thread', title: 'What comes up?', prompt: null },
}

/** What Vi's failed Sanity check took, recorded after the check: 1d6 Sanity and 1 Hit point. */
export const sanityLossEntry: JournalEntry = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7010',
  sessionNumber: 2,
  sceneNumber: 1,
  recordedAt: '2026-10-06T12:10:00+00:00',
  kind: 'sheet-change',
  content: {
    kind: 'sheet-change',
    characterId: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9001',
    characterName: 'Vi',
    changes: [
      { field: 'sanity', before: 65, after: 61, dice: { expression: '1d6', total: 4 } },
      { field: 'hp', before: 12, after: 11, dice: null },
    ],
  },
  flowStep: { key: 'shock', title: 'Does the sight break you?', prompt: null },
}

/** What the Flow decided on its own after a Chase roll: the band taken, a Tracker, a switch, the next scene and the end of the phase. */
export const flowOutcomeEntry: JournalEntry = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7011',
  sessionNumber: 2,
  sceneNumber: 1,
  recordedAt: '2026-10-06T12:10:00+00:00',
  kind: 'flowOutcome',
  content: {
    kind: 'flowOutcome',
    notes: [
      { kind: 'band', label: 'Strong hit' },
      { kind: 'tracker', key: 'threat', name: 'Threat', from: 3, to: 4 },
      { kind: 'switch', from: 'Chase', to: 'Combat' },
      { kind: 'nextScene', sceneType: 'Briefing' },
      { kind: 'phaseEnd', phase: 'Heist' },
    ],
  },
  flowStep: { key: 'shock', title: 'Does the sight break you?', prompt: null },
}

/** A note in session 2, scene 1 that attaches the entries with the given ids, in that order. */
export function noteAttaching(
  attachments: string[],
  id = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7098',
): JournalEntry {
  return recordedEntry({ kind: 'note', text: 'The door gives way.', attachments }, id)
}
