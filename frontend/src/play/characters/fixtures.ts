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
  roll: null,
  startMax: null,
  span: 1,
  options: null,
  capacity: null,
  maxStacks: null,
  categories: null,
}

/** A conditions field (ADR 0041): three options a Character can be marked with, in this order. */
export const conditionsField: SheetField = {
  ...base,
  key: 'wounds',
  label: 'Wounds',
  hint: 'Each costs a die.',
  type: 'conditions',
  options: [
    { key: 'hurt', label: 'Hurt' },
    { key: 'shaken', label: 'Shaken' },
    { key: 'tired', label: 'Tired' },
  ],
}

/** An inventory field (ADR 0045) in its own group, with room for ten slots. */
export const inventoryField: SheetField = {
  ...base,
  key: 'pack',
  label: 'Pack',
  group: 'Pack',
  type: 'inventory',
  capacity: 10,
  maxStacks: 50,
}

/** One field of each type: Identity (text), Stats (two numbers), Vitals (two tracks), then Gear and Notes (each in its own group). */
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
  {
    ...base,
    key: 'notes',
    label: 'Notes',
    group: 'Notes',
    type: 'text',
    multiline: true,
    maxLength: 2000,
  },
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

/** The layout of `sheet`: two columns, Identity across both, Stats in two columns beside Vitals, Gear and Notes across both, an empty group. */
export const sheetLayout: NonNullable<Campaign['pinnedRelease']['sheetLayout']> = {
  columns: 2,
  groups: [
    { name: 'Identity', columns: 1, span: 2 },
    { name: 'Stats', columns: 2, span: 1 },
    { name: 'Vitals', columns: 1, span: 1 },
    { name: 'Gear', columns: 1, span: 2 },
    { name: 'Notes', columns: 1, span: 2 },
    { name: 'Unused', columns: 1, span: 1 },
  ],
}

/** `nightJobCampaign` whose release has two checks: "Skill check" (with a difficulty) and "Luck". */
export const nightJobWithChecks: Campaign = {
  ...nightJobCampaign,
  checks: [skillCheck, luckCheck],
}

const rolled = (key: string, label: string, roll: string): SheetField => ({
  ...base,
  key,
  label,
  type: 'number',
  min: 1,
  max: 99,
  default: 1,
  roll,
})

/** The sheet of a release with structured creation (ADR 0040): two rolled characteristics inside the budget, Luck rolled outside it, Hit points starting at a tenth of the two. */
export const creationSheet: SheetField[] = [
  { ...base, key: 'role', label: 'Role', type: 'text', maxLength: 40 },
  rolled('str', 'Strength', '3d6*5'),
  rolled('con', 'Constitution', '(2d6+6)*5'),
  rolled('luck', 'Luck', '3d6*5'),
  { ...base, key: 'hp', label: 'Hit points', type: 'track', startMax: '({str} + {con}) / 10' },
]

/** Spend 100 points over Strength and Constitution, 15 to 90 each, or roll them. */
export const creationBudget: Omit<Campaign['pinnedRelease']['pointBudgets'][number], 'key'> = {
  label: 'Characteristic points',
  total: 100,
  fields: ['str', 'con'],
  min: 15,
  max: 90,
}

/** `nightJobCampaign` on a release that creates Characters by roll or by points. */
export const creationCampaign: Campaign = {
  ...nightJobCampaign,
  pinnedRelease: {
    ...nightJobCampaign.pinnedRelease,
    sheet: creationSheet,
    pointBudgets: [{ key: 'points', ...creationBudget }],
  },
  characters: [],
}

/** The release of `creationCampaign` with Lore added: 8 skill points, at most 3, a second budget no dice cover. */
export const loreField: SheetField = {
  ...base,
  key: 'lore',
  label: 'Lore',
  type: 'number',
  min: 0,
  max: 5,
  default: 0,
}

/** Two budgets: the characteristics, rollable, and the skill points (Lore), which are always spent. */
export const creationBudgets: Campaign['pinnedRelease']['pointBudgets'] = [
  { key: 'points', ...creationBudget },
  { key: 'skills', label: 'Skill points', total: 8, fields: ['lore'], min: null, max: 3 },
]

/** `creationCampaign` with a second budget no dice field covers. */
export const twoBudgetCampaign: Campaign = {
  ...creationCampaign,
  pinnedRelease: {
    ...creationCampaign.pinnedRelease,
    sheet: [...creationSheet, loreField],
    pointBudgets: creationBudgets,
  },
}
