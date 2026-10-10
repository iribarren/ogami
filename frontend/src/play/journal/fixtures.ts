import type { JournalEntry } from './useJournal'

// Shared by the journal tests and stories; they belong to `lostMineCampaign`.

export const gateNote: JournalEntry = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001',
  sessionNumber: 1,
  sceneNumber: 1,
  recordedAt: '2026-10-05T12:11:00+00:00',
  kind: 'note',
  flowStep: null,
  content: { kind: 'note', text: 'The gate is open.\nNobody guards it.' },
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
    steps: [
      {
        tableKey: 'weather',
        tableName: 'Weather',
        dice: '1d6',
        total: 6,
        text: 'A storm',
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
