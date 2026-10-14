import { lostMineCampaign, luckCheck, skillCheck } from '../campaigns/fixtures'
import type { Campaign } from '../campaigns/useCampaigns'
import { allyTag, leaderTag } from '../lists/fixtures'

// Shared by the Characters panel tests and stories.

export type Character = Campaign['characters'][number]
export type SheetField = Campaign['pinnedRelease']['sheet'][number]

/** A field with every type property empty; each fixture sets its own. */
const base: SheetField = {
  key: '',
  label: '',
  hint: null,
  group: null,
  type: 'text',
  min: null,
  max: null,
  default: null,
  multiline: null,
  maxLength: null,
  itemLabel: null,
  maxItems: null,
  formula: null,
}

/** One field of each type: Identity (text), Stats (two numbers), Vitals (two tracks), then Gear and Notes. */
export const sheet: SheetField[] = [
  { ...base, key: 'role', label: 'Role', group: 'Identity', type: 'text', maxLength: 40 },
  {
    ...base,
    key: 'reflexes',
    label: 'Reflexes',
    hint: 'How fast you react.',
    group: 'Stats',
    type: 'number',
    min: 1,
    max: 8,
    default: 5,
  },
  {
    ...base,
    key: 'body',
    label: 'Body',
    group: 'Stats',
    type: 'number',
    min: 1,
    max: 8,
    default: 4,
  },
  { ...base, key: 'hp', label: 'HP', group: 'Vitals', type: 'track', max: 40 },
  { ...base, key: 'humanity', label: 'Humanity', group: 'Vitals', type: 'track' },
  {
    ...base,
    key: 'gear',
    label: 'Gear',
    group: 'Gear',
    type: 'list',
    itemLabel: 'Item',
    maxItems: 3,
  },
  { ...base, key: 'notes', label: 'Notes', type: 'text', multiline: true, maxLength: 2000 },
]

/** A derived field over two numbers of `sheet`; add it to a sheet where a test needs one. */
export const initiativeField: SheetField = {
  ...base,
  key: 'initiative',
  label: 'Initiative',
  hint: 'Reflexes plus body.',
  group: 'Stats',
  type: 'derived',
  formula: '{reflexes} + {body}',
}

export const viCharacter: Character = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9001',
  name: 'Vi',
  tags: ['leader'],
  freeTags: ['Fixer'],
  values: {
    role: 'Solo',
    reflexes: 7,
    body: 6,
    hp: { current: 30, max: 40 },
    humanity: { current: 20, max: 50 },
    gear: ['Knife', 'Agent'],
    notes: 'Owes a favor.',
  },
  derived: {},
  promotedFrom: null,
}

/** A Character saved before the sheet gained fields: no values at all. */
export const oldCharacter: Character = {
  id: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9002',
  name: 'Old hand',
  tags: [],
  freeTags: [],
  values: {},
  derived: {},
  promotedFrom: null,
}

/** `lostMineCampaign` with a sheet, release Tags and one Character. */
export const nightJobCampaign: Campaign = {
  ...lostMineCampaign,
  tags: [leaderTag, allyTag],
  pinnedRelease: { ...lostMineCampaign.pinnedRelease, sheet },
  characters: [viCharacter],
}

/** A release without sheet fields: Characters hold a name and Tags only. */
export const lostMineWithCharacters: Campaign = {
  ...lostMineCampaign,
  tags: [leaderTag, allyTag],
  characters: [{ ...viCharacter, values: {} }],
}

/** `nightJobCampaign` whose release has two checks: "Skill check" (with a difficulty) and "Luck". */
export const nightJobWithChecks: Campaign = {
  ...nightJobCampaign,
  checks: [skillCheck, luckCheck],
}
