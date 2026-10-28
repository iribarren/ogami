import { slugOf } from '../checks/checkDraft'
import type { GameSystemSheet, GearRequest } from '../sheet/useGameSystemSheet'

export type GearEntry = GameSystemSheet['gear'][number]

/**
 * The form state of one catalog entry: numbers stay text until saved, and what is empty is not
 * sent. While `follow` is set a new entry's key follows its name, until the key is typed.
 */
export interface GearDraft {
  key: string
  follow: boolean
  name: string
  cost: string
  slots: string
  category: string
  description: string
}

export const blankGear: GearDraft = {
  key: '',
  follow: true,
  name: '',
  cost: '',
  slots: '',
  category: '',
  description: '',
}

export function readGear(entry: GearEntry | null): GearDraft {
  return entry === null
    ? blankGear
    : {
        key: entry.key,
        follow: false,
        name: entry.name,
        cost: String(entry.cost),
        slots: String(entry.slots),
        category: entry.category ?? '',
        description: entry.description ?? '',
      }
}

/** The limits of the publisher; it stays the authority on the rest (unique names, key format). */
export const gearLimits = { name: 60, cost: 1_000_000, slots: 100, category: 40, description: 500 }

export type GearErrors = Partial<Record<keyof Omit<GearDraft, 'follow'>, string>>

const whole = /^\d+$/

/** The request for a draft, or what is wrong with it. */
export function writeGear(
  draft: GearDraft,
  takenKeys: string[],
): { request: GearRequest } | { errors: GearErrors } {
  const errors: GearErrors = {}
  if (!/^[a-z0-9-]{1,64}$/.test(draft.key)) {
    errors.key = 'Use 1 to 64 lower case letters, digits and hyphens.'
  } else if (takenKeys.includes(draft.key)) {
    errors.key = 'Another entry uses this key.'
  }
  const name = draft.name.trim()
  if (name === '' || name.length > gearLimits.name) {
    errors.name = `Enter a name of up to ${String(gearLimits.name)} characters.`
  }
  const cost = draft.cost.trim()
  if (!whole.test(cost) || Number(cost) > gearLimits.cost) {
    errors.cost = `Enter a whole number from 0 to ${gearLimits.cost.toLocaleString('en-US')}.`
  }
  const slots = draft.slots.trim()
  if (slots !== '' && (!whole.test(slots) || Number(slots) > gearLimits.slots)) {
    errors.slots = `Enter a whole number from 0 to ${String(gearLimits.slots)}, or leave it empty for 1.`
  }
  const category = draft.category.trim()
  if (category.length > gearLimits.category) {
    errors.category = `Use at most ${String(gearLimits.category)} characters.`
  }
  const description = draft.description.trim()
  if (description.length > gearLimits.description) {
    errors.description = `Use at most ${String(gearLimits.description)} characters.`
  }
  if (Object.keys(errors).length > 0) {
    return { errors }
  }

  return {
    request: {
      name,
      cost: Number(cost),
      ...(slots === '' ? {} : { slots: Number(slots) }),
      ...(category === '' ? {} : { category }),
      ...(description === '' ? {} : { description }),
    },
  }
}

/** The draft with a new name; the key follows it while it has not been typed. */
export function withName(draft: GearDraft, name: string): GearDraft {
  return draft.follow ? { ...draft, name, key: slugOf(name) } : { ...draft, name }
}
