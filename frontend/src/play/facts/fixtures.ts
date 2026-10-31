import type { Campaign } from '../campaigns/useCampaigns'
import { lostMineWithLists, miraNpc, saltmarshPlace, vaultThread } from '../lists/fixtures'

// Shared by the Facts panel tests and stories.

export type Fact = Campaign['facts'][number]
export type FactSlot = Campaign['factSlots'][number]

export const citySlot: FactSlot = {
  key: 'city',
  label: 'The city',
  type: 'text',
  hint: 'Where does the story begin?',
}
export const princeSlot: FactSlot = { key: 'prince', label: 'The Prince', type: 'npc', hint: null }
export const goalSlot: FactSlot = {
  key: 'goal',
  label: 'The goal',
  type: 'thread',
  hint: 'What does the character want?',
}

export const siteSlot: FactSlot = { key: 'site', label: 'The site', type: 'place', hint: null }

/** A filled text slot, linked to Mira Vane. */
export const cityFact: Fact = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9001',
  slot: 'city',
  label: 'The city',
  text: 'Ashford',
  subject: null,
  value: 'Ashford',
  links: [{ kind: 'npc', id: miraNpc.id, name: miraNpc.name }],
}

/** A filled NPC slot: Mira Vane is the Prince. */
export const princeFact: Fact = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9002',
  slot: 'prince',
  label: 'The Prince',
  text: null,
  subject: { kind: 'npc', id: miraNpc.id, name: miraNpc.name },
  value: miraNpc.name,
  links: [],
}

/** A filled Place slot: Saltmarsh is the site. */
export const siteFact: Fact = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9005',
  slot: 'site',
  label: 'The site',
  text: null,
  subject: { kind: 'place', id: saltmarshPlace.id, name: saltmarshPlace.name },
  value: saltmarshPlace.name,
  links: [],
}

export const guildFact: Fact = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9003',
  slot: null,
  label: 'The guild',
  text: 'Owes a debt to the Prince.',
  subject: null,
  value: 'Owes a debt to the Prince.',
  links: [{ kind: 'thread', id: vaultThread.id, name: vaultThread.name }],
}

export const oathFact: Fact = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9004',
  slot: null,
  label: 'The oath',
  text: 'Never steal from the poor.',
  subject: null,
  value: 'Never steal from the poor.',
  links: [],
}

/** `lostMineWithLists` with three Fact slots (the goal unfilled) and two free facts. */
export const lostMineWithFacts: Campaign = {
  ...lostMineWithLists,
  factSlots: [citySlot, princeSlot, goalSlot],
  facts: [cityFact, princeFact, guildFact, oathFact],
}
