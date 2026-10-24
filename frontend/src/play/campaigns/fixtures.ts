import type { Campaign, CampaignSummary, GameSystemSummary } from './useCampaigns'
import type { ReleaseUpgrade } from './useReleaseUpgrade'

// Shared by the campaign tests and stories.

export const freeJournal: GameSystemSummary = {
  gameSystemKey: 'free-journal',
  name: 'Free journal',
  description: 'Play any setting as a journal.',
  version: 2,
  flows: [],
  credits: null,
}

export const mythicStyle: GameSystemSummary = {
  gameSystemKey: 'mythic-style',
  name: 'Mythic style',
  description: null,
  version: 1,
  flows: [],
  credits: null,
}

/** A GameSystem with two Flows, the second one the default. */
export const heist: GameSystemSummary = {
  gameSystemKey: 'heist',
  name: 'Heist',
  description: 'Plan and pull a job.',
  version: 1,
  flows: [
    {
      key: 'quick',
      name: 'Quick job',
      description: 'One phase, no hooks.',
      introduction: null,
      default: false,
    },
    { key: 'full', name: 'Full heist', description: null, introduction: null, default: true },
  ],
  credits: null,
}

export const lostMine: CampaignSummary = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6101',
  name: 'The lost mine',
  gameSystemKey: 'free-journal',
  gameSystemName: 'Free journal',
  releaseVersion: 2,
  // Midday UTC, so the date reads the same in every time zone the tests run in.
  createdAt: '2026-10-05T12:00:00+00:00',
}

export const sunkenTemple: CampaignSummary = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6102',
  name: 'The sunken temple',
  gameSystemKey: 'mythic-style',
  gameSystemName: 'Mythic style',
  releaseVersion: 1,
  createdAt: '2026-09-28T12:00:00+00:00',
}

/** A fan preset: credits with a license link, and a default Flow with a two-paragraph introduction. */
export const fanPreset: GameSystemSummary = {
  gameSystemKey: 'night-job',
  name: 'Night Job',
  description: 'A heist one-shot.',
  version: 1,
  flows: [
    {
      key: 'heist',
      name: 'Heist',
      description: null,
      introduction: 'You play alone.\n\nThe guide asks one thing at a time.',
      default: true,
    },
  ],
  credits: {
    notice: 'Night Job is unofficial content and is not approved or endorsed by the publisher.',
    license: 'Homebrew Content Policy',
    url: 'https://example.com/homebrew-policy',
  },
}

/** `lostMine` as `GET /api/campaigns/{id}` returns it, in session 2, scene 1. */
export const lostMineCampaign: Campaign = {
  id: lostMine.id,
  name: lostMine.name,
  createdAt: lostMine.createdAt,
  pinnedRelease: {
    gameSystemKey: 'free-journal',
    gameSystemName: 'Free journal',
    version: 2,
    theme: null,
    credits: null,
    sheet: [],
    sheetLayout: null,
    pointBudget: null,
  },
  sessions: [
    {
      number: 1,
      startedAt: '2026-10-05T12:05:00+00:00',
      party: [],
      scenes: [
        {
          number: 1,
          title: 'At the gate',
          startedAt: '2026-10-05T12:10:00+00:00',
          kind: 'scene',
          sceneType: null,
          sceneTypeName: null,
          hook: null,
          cast: [],
          characters: [],
        },
      ],
      endedAt: '2026-10-05T14:00:00+00:00',
    },
    {
      number: 2,
      startedAt: '2026-10-06T12:00:00+00:00',
      party: [],
      scenes: [
        {
          number: 1,
          title: 'Into the dark',
          startedAt: '2026-10-06T12:05:00+00:00',
          kind: 'scene',
          sceneType: null,
          sceneTypeName: null,
          hook: null,
          cast: [],
          characters: [],
        },
      ],
      endedAt: null,
    },
  ],
  currentSessionNumber: 2,
  currentSceneNumber: 1,
  oracleTables: [
    { key: 'weather', name: 'Weather' },
    { key: 'action', name: 'Action' },
  ],
  likelihoodOracles: [
    {
      key: 'fate',
      name: 'Fate question',
      levels: [
        { key: 'unlikely', label: 'Unlikely' },
        { key: 'even', label: 'Even odds' },
        { key: 'likely', label: 'Likely' },
      ],
      chaos: { min: 1, max: 9, neutral: 5 },
      chaosTracker: null,
    },
    {
      key: 'yes-no',
      name: 'Yes/no question',
      levels: [
        { key: 'even', label: 'Even odds' },
        { key: 'likely', label: 'Likely' },
      ],
      chaos: null,
      chaosTracker: null,
    },
  ],
  trackers: [],
  flowKey: null,
  flowRun: null,
  flows: [],
  sceneTypes: [],
  tags: [],
  checks: [],
  npcs: [],
  threads: [],
  factSlots: [],
  facts: [],
  characters: [],
}

/** `lostMineCampaign` right after starting session 3: no scene yet. */
export const lostMineInNewSession: Campaign = {
  ...lostMineCampaign,
  sessions: [
    ...lostMineCampaign.sessions.map((session) => ({
      ...session,
      endedAt: session.endedAt ?? '2026-10-06T14:00:00+00:00',
    })),
    { number: 3, startedAt: '2026-10-07T12:00:00+00:00', scenes: [], endedAt: null, party: [] },
  ],
  currentSessionNumber: 3,
  currentSceneNumber: null,
}

/** A campaign just created: no session yet. */
export function newCampaign(name: string, gameSystem: GameSystemSummary): Campaign {
  return {
    id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6199',
    name,
    createdAt: '2026-10-06T12:00:00+00:00',
    pinnedRelease: {
      gameSystemKey: gameSystem.gameSystemKey,
      gameSystemName: gameSystem.name,
      version: gameSystem.version,
      theme: null,
      credits: gameSystem.credits,
      sheet: [],
      sheetLayout: null,
      pointBudget: null,
    },
    sessions: [],
    currentSessionNumber: null,
    currentSceneNumber: null,
    oracleTables: [],
    likelihoodOracles: [],
    trackers: [],
    flowKey: null,
    flowRun: null,
    flows: [],
    sceneTypes: [],
    tags: [],
    checks: [],
    npcs: [],
    threads: [],
    factSlots: [],
    facts: [],
    characters: [],
  }
}

/** A clock, a counter with levels and a counter with neither, as the API lists them. */
type Tracker = Campaign['trackers'][number]

export const alarmTracker: Tracker = {
  key: 'alarm',
  name: 'Alarm',
  kind: 'clock',
  hint: 'At 6/6 security locks down',
  min: 0,
  max: 6,
  segments: 6,
  levels: [],
  value: 2,
  levelLabel: null,
}

export const heatTracker: Tracker = {
  key: 'heat',
  name: 'Heat',
  kind: 'counter',
  hint: null,
  min: -5,
  max: 5,
  segments: null,
  levels: [
    { upTo: -1, label: 'Cold' },
    { upTo: 2, label: 'Warm' },
    { upTo: null, label: 'Hot' },
  ],
  value: 0,
  levelLabel: 'Warm',
}

export const edgeTracker: Tracker = {
  key: 'edge',
  name: 'Edge',
  kind: 'counter',
  hint: null,
  min: 0,
  max: 3,
  segments: null,
  levels: [],
  value: 1,
  levelLabel: null,
}

export const heistTrackers: Tracker[] = [alarmTracker, heatTracker, edgeTracker]

/**
 * `lostMineCampaign` on a release with Scene Types and Trackers, in a scene of the Scene Type
 * Legwork (shortcut: the oracle "fate"); the Flow "heist" selects the oracle "yes-no" and the
 * Trackers "alarm" and "heat". Played freely: set `flowKey` and `flowRun` to guide it.
 */
export const lostMineWithTypes: Campaign = {
  ...lostMineCampaign,
  sessions: lostMineCampaign.sessions.map((session) => ({
    ...session,
    scenes: session.scenes.map((scene) =>
      session.number === 2 ? { ...scene, sceneType: 'legwork', sceneTypeName: 'Legwork' } : scene,
    ),
  })),
  trackers: heistTrackers,
  flows: [
    {
      key: 'heist',
      name: 'Heist',
      description: null,
      introduction: null,
      default: true,
      defaultView: 'journal',
      oracles: ['yes-no'],
      trackers: ['alarm', 'heat'],
    },
  ],
  sceneTypes: [
    {
      key: 'legwork',
      name: 'Legwork',
      purpose: 'Learn about the target.',
      tips: null,
      oracles: ['fate'],
    },
    {
      key: 'firefight',
      name: 'Firefight',
      purpose: 'Shoot your way out.',
      tips: null,
      oracles: [],
    },
  ],
}

/** A check as the API lists it: 1d10 against a Difficulty Value the player sets. */
type CampaignCheck = Campaign['checks'][number]

export const skillCheck: CampaignCheck = {
  key: 'skill',
  label: 'Skill check',
  hint: 'A stat and 1d10 against a Difficulty Value.',
  dice: '1d10',
  difficulty: { label: 'Difficulty Value', default: 13, min: 9, max: 29 },
}

/** A check that declares no difficulty. */
export const luckCheck: CampaignCheck = {
  key: 'luck',
  label: 'Luck',
  hint: null,
  dice: 'd%',
  difficulty: null,
}

/** `freeJournal` as a release newer than the one `lostMineCampaign` is pinned to. */
export const freeJournalV3: GameSystemSummary = { ...freeJournal, version: 3 }

/** What upgrading `lostMineCampaign` (v2) to v3 changes: every section of the plan. */
export const lostMineUpgrade: ReleaseUpgrade = {
  upToDate: false,
  currentVersion: 2,
  plan: {
    from: { version: 2, name: 'Free journal' },
    to: { version: 3, name: 'Free journal' },
    characters: [
      {
        id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001',
        name: 'Vi',
        changes: [
          { fieldKey: 'luck', fieldName: 'Luck', kind: 'clamped', before: 9, after: 5 },
          { fieldKey: 'grit', fieldName: 'Grit', kind: 'added', before: null, after: 1 },
          {
            fieldKey: 'hp',
            fieldName: 'Hit points',
            kind: 'clamped',
            before: { current: 8, max: 8 },
            after: { current: 6, max: 6 },
          },
          {
            fieldKey: 'gear',
            fieldName: 'Gear',
            kind: 'trimmed',
            before: ['rope', 'lamp', 'knife'],
            after: ['rope', 'lamp'],
          },
          {
            fieldKey: 'motto',
            fieldName: 'Motto',
            kind: 'reset',
            before: 'Never again',
            after: '',
          },
          { fieldKey: 'fame', fieldName: 'Fame', kind: 'removed', before: 3, after: null },
        ],
        droppedTags: ['thief'],
      },
    ],
    npcs: [{ id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7002', name: 'Old Tam', tags: ['guard'] }],
    threads: [
      { id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7003', name: 'The missing map', tags: ['quest'] },
    ],
    trackers: [
      { trackerKey: 'heat', name: 'Heat', kind: 'clamped', before: 9, after: 6 },
      { trackerKey: 'luck-pool', name: 'Luck pool', kind: 'removed', before: 2, after: null },
    ],
    facts: [
      {
        id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7004',
        slotKey: 'patron',
        label: 'Patron',
        text: 'A baron in debt.',
      },
    ],
    flow: { fromFlowKey: 'full', toFlowKey: 'quick', restarted: true },
    fingerprint: 'a'.repeat(64),
  },
}

/** A plan with one Tracker change only: the other sections are omitted. */
export const trackerOnlyUpgrade: ReleaseUpgrade = {
  upToDate: false,
  currentVersion: 2,
  plan: {
    from: { version: 2, name: 'Free journal' },
    to: { version: 3, name: 'Free journal' },
    characters: [],
    npcs: [],
    threads: [],
    trackers: [{ trackerKey: 'heat', name: 'Heat', kind: 'clamped', before: 9, after: 6 }],
    facts: [],
    flow: { fromFlowKey: null, toFlowKey: null, restarted: false },
    fingerprint: 'b'.repeat(64),
  },
}

export const upToDateUpgrade: ReleaseUpgrade = { upToDate: true, currentVersion: 2, plan: null }
