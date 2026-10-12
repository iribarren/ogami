import { lostMineCampaign } from '../campaigns/fixtures'
import type { Campaign } from '../campaigns/useCampaigns'

// Shared by the NPC and Thread panel tests and stories.

type Tag = Campaign['tags'][number]
export type Npc = Campaign['npcs'][number]
export type Thread = Campaign['threads'][number]

export const leaderTag: Tag = { key: 'leader', label: 'Leader', hint: 'Runs a faction or a crew.' }
export const allyTag: Tag = { key: 'ally', label: 'Ally', hint: null }

export const miraNpc: Npc = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001',
  name: 'Mira Vane',
  agenda: 'Wants the ledger back.',
  tags: ['leader'],
  freeTags: ['Fence'],
  weight: 2,
  status: 'open',
}

export const brannNpc: Npc = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7002',
  name: 'Brann',
  agenda: null,
  tags: [],
  freeTags: [],
  weight: 1,
  status: 'open',
}

export const oldGuardNpc: Npc = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7003',
  name: 'The old guard',
  agenda: null,
  tags: ['ally'],
  freeTags: [],
  weight: 1,
  status: 'closed',
}

export const vaultThread: Thread = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f8001',
  name: 'Rob the vault',
  tags: ['leader'],
  freeTags: ['Vault'],
  weight: 3,
  status: 'open',
}

export const ledgerThread: Thread = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f8002',
  name: 'Find the ledger',
  tags: [],
  freeTags: [],
  weight: 1,
  status: 'closed',
}

/** `lostMineCampaign` with release Tags, open and closed NPCs and Threads. */
export const lostMineWithLists: Campaign = {
  ...lostMineCampaign,
  tags: [leaderTag, allyTag],
  npcs: [miraNpc, brannNpc, oldGuardNpc],
  threads: [vaultThread, ledgerThread],
}
