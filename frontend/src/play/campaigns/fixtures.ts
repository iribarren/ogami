import type { Campaign, CampaignSummary, GameSystemSummary } from './useCampaigns'

// Shared by the campaign tests and stories.

export const freeJournal: GameSystemSummary = {
  gameSystemKey: 'free-journal',
  name: 'Free journal',
  description: 'Play any setting as a journal.',
  version: 2,
}

export const mythicStyle: GameSystemSummary = {
  gameSystemKey: 'mythic-style',
  name: 'Mythic style',
  description: null,
  version: 1,
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

/** `lostMine` as `GET /api/campaigns/{id}` returns it, in session 2, scene 1. */
export const lostMineCampaign: Campaign = {
  id: lostMine.id,
  name: lostMine.name,
  createdAt: lostMine.createdAt,
  pinnedRelease: { gameSystemKey: 'free-journal', gameSystemName: 'Free journal', version: 2 },
  sessions: [
    {
      number: 1,
      startedAt: '2026-10-05T12:05:00+00:00',
      scenes: [
        {
          number: 1,
          title: 'At the gate',
          startedAt: '2026-10-05T12:10:00+00:00',
          kind: 'scene',
          sceneType: null,
          sceneTypeName: null,
          hook: null,
        },
      ],
    },
    {
      number: 2,
      startedAt: '2026-10-06T12:00:00+00:00',
      scenes: [
        {
          number: 1,
          title: 'Into the dark',
          startedAt: '2026-10-06T12:05:00+00:00',
          kind: 'scene',
          sceneType: null,
          sceneTypeName: null,
          hook: null,
        },
      ],
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
}

/** `lostMineCampaign` right after starting session 3: no scene yet. */
export const lostMineInNewSession: Campaign = {
  ...lostMineCampaign,
  sessions: [
    ...lostMineCampaign.sessions,
    { number: 3, startedAt: '2026-10-07T12:00:00+00:00', scenes: [] },
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
    },
    sessions: [],
    currentSessionNumber: null,
    currentSceneNumber: null,
    oracleTables: [],
    likelihoodOracles: [],
    trackers: [],
  }
}
