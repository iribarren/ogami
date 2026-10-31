import {
  gripCheck,
  gritCheck,
  lostMineCampaign,
  skillCheck,
  sneakCheck,
} from '../campaigns/fixtures'
import type { Campaign } from '../campaigns/useCampaigns'
import {
  eddiesField,
  gearCatalog,
  inventoryField,
  nightJobCampaign,
  nightJobWithChecks,
  viCharacter,
} from '../characters/fixtures'
import { gritEntry } from '../journal/fixtures'
import {
  allyTag,
  brannNpc,
  leaderTag,
  lostMineWithLists,
  miraNpc,
  saltmarshPlace,
} from '../lists/fixtures'
import type { FlowRun, FlowStep } from './useFlowRun'

type GearEntry = Campaign['pinnedRelease']['gear'][number]

// Shared by the guided panel's tests and stories; the campaign is `lostMineCampaign` along the Flow "tour".

export const tour = {
  key: 'tour',
  name: 'Tour',
  purpose: 'Meet someone on the road.',
  tips: null,
  oracles: [],
}
export const quiet = {
  key: 'quiet',
  name: 'Quiet',
  purpose: 'Rest and take stock.',
  tips: null,
  oracles: [],
}

/** A step with nothing set but its key and title; the kinds below fill in what they need. */
function step(fields: Partial<FlowStep> & Pick<FlowStep, 'key' | 'kind' | 'title'>): FlowStep {
  return {
    prompt: null,
    tip: null,
    mandatory: false,
    oracle: null,
    likelihood: null,
    table: null,
    dice: null,
    options: [],
    pick: null,
    fills: [],
    check: null,
    checks: [],
    difficulty: null,
    difficultyFixed: false,
    characters: [],
    inspiration: [],
    changesSheet: false,
    pending: null,
    shop: null,
    ...fields,
  }
}

export const promptStep = step({
  key: 'intro',
  kind: 'prompt',
  title: 'Who walks in?',
  prompt: 'Describe the first person.',
  tip: 'Keep it short.',
})
/** A `prompt` step offering the tables Weather and Action as inspiration, and one the campaign lacks. */
export const inspirationStep = step({
  key: 'notice',
  kind: 'prompt',
  title: 'What do you notice?',
  inspiration: ['weather', 'action', 'ghost'],
})
export const oracleStep = step({
  key: 'ask',
  kind: 'oracle',
  title: 'Is the gate open?',
  oracle: 'fate',
})
export const fixedOracleStep = { ...oracleStep, key: 'ask-even', likelihood: 'even' }
export const tableStep = step({
  key: 'omen',
  kind: 'table',
  title: 'Read the omen',
  table: 'weather',
})
export const rollStep = step({ key: 'dice', kind: 'roll', title: 'Push your luck', dice: '1d6' })
export const choiceStep = step({
  key: 'fork',
  kind: 'choice',
  title: 'Which way?',
  options: [
    { key: 'left', label: 'Go left', changesSheet: false },
    { key: 'right', label: 'Go right', changesSheet: false },
  ],
})
/** A `choice` step whose option "Learned" adds experience: the player names the Character. */
export const sheetChoiceStep = step({
  key: 'learned',
  kind: 'choice',
  title: 'Did you learn something?',
  options: [
    { key: 'learned', label: 'Learned', changesSheet: true },
    { key: 'nothing', label: 'Nothing', changesSheet: false },
  ],
})
/** A `prompt` step whose answer costs a Character hit points: the player names the Character. */
export const sheetPromptStep = step({
  key: 'cost',
  kind: 'prompt',
  title: 'What did it cost you?',
  changesSheet: true,
})

/** A `character` step: the player picks a Character of the campaign, or creates one. */
export const characterStep = step({
  key: 'crew',
  kind: 'character',
  title: 'Who are you?',
  prompt: 'Create the Character you play.',
  mandatory: true,
})

/** A `check` step: Vi rolls the "Skill check", the player sets the difficulty. */
export const checkStep = step({
  key: 'sneak',
  kind: 'check',
  title: 'Slip past the guard',
  prompt: 'Roll to get by unseen.',
  check: skillCheck.key,
  characters: [{ id: viCharacter.id, name: viCharacter.name }],
})

/** A `check` step offering the check set Sneak and Grip (pool checks): the player picks which one Vi rolls. */
export const checkSetStep = step({
  key: 'sneak',
  kind: 'check',
  title: 'Slip past the guard',
  prompt: 'Roll to get by unseen.',
  checks: [sneakCheck.key, gripCheck.key],
  characters: [{ id: viCharacter.id, name: viCharacter.name }],
})

/** `checkStep` rolling the pushable "Grit": Vi's roll, `gritEntry`, pends until Keep or Push. */
export const pendingGritStep = step({
  key: 'sneak',
  kind: 'check',
  title: 'Slip past the guard',
  prompt: 'Roll to get by unseen.',
  check: gritCheck.key,
  characters: [{ id: viCharacter.id, name: viCharacter.name }],
  pending: { entryId: gritEntry.id, characterId: viCharacter.id },
})

type PickDetails = NonNullable<FlowStep['pick']>

/** A `pick` step of NPCs; the details default to choosing one of Mira and Brann, tagged Leader. */
const pickStep = (key: string, title: string, details: Partial<PickDetails>) =>
  step({
    key,
    kind: 'pick',
    title,
    pick: {
      of: 'npc',
      mode: 'choose',
      min: 1,
      max: 1,
      tags: [{ key: leaderTag.key, label: leaderTag.label }],
      candidates: [
        { id: miraNpc.id, name: miraNpc.name },
        { id: brannNpc.id, name: brannNpc.name },
      ],
      ...details,
    },
  })

export const chooseOneStep = pickStep('who', 'Who shows up?', {})
export const chooseUpToTwoStep = pickStep('who-two', 'Who comes along?', { min: 1, max: 2 })
export const chooseTwoStep = pickStep('who-both', 'Who must come?', { min: 2, max: 2 })
export const randomPickStep = pickStep('draw', 'Who is it?', { mode: 'random', tags: [] })
export const randomThreadStep = pickStep('draw-thread', 'What comes up?', {
  of: 'thread',
  mode: 'random',
  tags: [{ key: allyTag.key, label: allyTag.label }],
})
/** A `pick` step of Places: choose Saltmarsh. */
export const choosePlaceStep = pickStep('where', 'Where do you go?', {
  of: 'place',
  tags: [],
  candidates: [{ id: saltmarshPlace.id, name: saltmarshPlace.name }],
})
/** A `pick` step of Characters: choose one of Vi (the candidates are the campaign's Characters). */
export const chooseCharacterStep = pickStep('who-plays', 'Who takes the job?', {
  of: 'character',
  tags: [],
  candidates: [{ id: viCharacter.id, name: viCharacter.name }],
})
export const noMatchCharacterStep = pickStep('nobody-plays', 'Who is left to play?', {
  of: 'character',
  tags: [],
  candidates: [],
})
export const noMatchChooseStep = pickStep('nobody', 'Who is left?', { candidates: [] })
export const noMatchRandomStep = pickStep('nobody-drawn', 'Who is drawn?', {
  mode: 'random',
  candidates: [],
})

const progress: FlowRun['progress'] = {
  act: 'Act 1',
  phaseKey: 'start',
  phase: 'The start',
  sceneType: 'Tour',
  part: 'setup',
  stepNumber: 1,
  stepCount: 3,
  phaseDescription: null,
  sceneNumber: 1,
}

/** A FlowRun waiting on `current`, in the scene "Into the dark" (the Scene Type Tour). */
export function atStep(current: FlowStep, overrides: Partial<FlowRun> = {}): FlowRun {
  return {
    status: 'active',
    waitsForSession: false,
    progress,
    step: current,
    pick: null,
    canMoveOn: false,
    next: `Setup: ${current.title}`,
    canEndSession: false,
    ...overrides,
  }
}

/** What the phase of the FlowRuns above says it is for. */
export const phaseDescription = 'Find out what the gate hides, then decide how to get in.'

/** The FlowRun with the description of its phase set. */
export const describing = (flowRun: FlowRun): FlowRun => ({
  ...flowRun,
  progress: flowRun.progress === null ? null : { ...flowRun.progress, phaseDescription },
})

/** A FlowRun at the scene pick, in `rule`'s words. */
export const atTheScenePick = (overrides: Partial<NonNullable<FlowRun['pick']>> = {}): FlowRun =>
  atStep(promptStep, {
    progress: {
      ...progress,
      sceneType: null,
      part: null,
      stepNumber: null,
      stepCount: null,
      sceneNumber: null,
    },
    step: null,
    pick: { rule: 'player', cards: [tour, quiet], table: null, forced: false, ...overrides },
    next: 'Scene: choose a Scene Type',
    canEndSession: true,
  })

/** Open play in the scene: no step waits. */
export const inOpenPlay: FlowRun = atStep(promptStep, {
  step: null,
  progress: { ...progress, part: 'open', stepNumber: null, stepCount: null },
  next: 'Closing: What changed?',
})

/** `lostMineCampaign` along the Flow "tour", with the given FlowRun, opening in `defaultView`. */
export function guided(flowRun: FlowRun, defaultView: 'journal' | 'focus' = 'journal'): Campaign {
  return {
    ...lostMineCampaign,
    flowKey: 'tour',
    flowRun,
    flows: [
      {
        key: 'tour',
        name: 'Tour',
        description: null,
        introduction: null,
        default: true,
        defaultView,
        oracles: [],
        trackers: [],
      },
    ],
    sceneTypes: [tour, quiet],
  }
}

/** `guided` with the NPCs and Threads of `lostMineWithLists` (the pick steps' candidates). */
export const guidedWithLists = (
  flowRun: FlowRun,
  defaultView: 'journal' | 'focus' = 'journal',
) => ({
  ...guided(flowRun, defaultView),
  tags: lostMineWithLists.tags,
  npcs: lostMineWithLists.npcs,
  threads: lostMineWithLists.threads,
})

/** `guided` in the Night Job campaign: a sheet, release Tags and one Character (Vi). */
export const guidedWithCharacters = (flowRun: FlowRun) => ({
  ...guided(flowRun),
  tags: nightJobCampaign.tags,
  pinnedRelease: nightJobCampaign.pinnedRelease,
  characters: nightJobCampaign.characters,
})

/** `guidedWithCharacters` whose release has the checks "Skill check" and "Luck". */
export const guidedWithChecks = (flowRun: FlowRun) => ({
  ...guidedWithCharacters(flowRun),
  checks: nightJobWithChecks.checks,
})

const usedPistol: GearEntry = {
  key: 'used-pistol',
  name: 'Used pistol',
  cost: 50,
  slots: 1,
  category: 'Weapons',
  description: 'Cheap and loud.',
}
const ration: GearEntry = {
  key: 'ration',
  name: 'Ration',
  cost: 5,
  slots: 1,
  category: 'Food',
  description: null,
}

/** A `shop` step (ADR 0045) selling a Used pistol and a Ration, buying at half the cost, paying in eddies. */
export const shopStep = step({
  key: 'stalls',
  kind: 'shop',
  title: 'What do you buy?',
  mandatory: true,
  shop: {
    onSale: [usedPistol, ration],
    sellRate: 50,
    currency: { field: 'eddies', unit: 'eb' },
  },
})

/** `guidedWithCharacters` whose release has a currency, a gear catalog and a Pack; Vi holds 100 eb and two Used pistols. */
export const guidedWithShop = (flowRun: FlowRun): Campaign => {
  const base = guidedWithCharacters(flowRun)
  return {
    ...base,
    pinnedRelease: {
      ...base.pinnedRelease,
      sheet: [...base.pinnedRelease.sheet, eddiesField, inventoryField],
      gear: gearCatalog,
      currency: { field: 'eddies', unit: 'eb' },
    },
    characters: [
      {
        ...viCharacter,
        values: {
          ...viCharacter.values,
          eddies: 100,
          pack: [
            { gear: 'used-pistol', quantity: 2 },
            { name: 'Rope', quantity: 1, slots: 0 },
          ],
        },
      },
    ],
  }
}
