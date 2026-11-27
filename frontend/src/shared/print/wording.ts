import type { EntryEffect, OutcomeEffect, Release, ReleaseTracker } from './release'

/** How a card names each band of a check; a band this table lacks is named by its own key. */
export const BAND_LABELS = new Map([
  ['failure', 'Failing:'],
  ['badly', 'Going badly'],
  ['done', 'Done:'],
  ['short', 'Short:'],
  ['wrong', 'Wrong:'],
])

/** An effect a card has no wording for: a generated card leaves it out, a paper edition refuses it. */
export class UnwordableEffect extends Error {
  override name = 'UnwordableEffect'
}

/** The title of a step as a sentence, so the text after it reads on: "Laid down in time." */
export const sentence = (title: string) => (/[.?!:]$/.test(title) ? title : `${title}.`)

export const words = (...parts: (string | undefined)[]) => parts.filter((part) => part).join(' ')

export const capitalised = (word: string) => `${word.charAt(0).toUpperCase()}${word.slice(1)}`

/** "-1d6" is "d6" and "+2d4" is "2d4": the dice a card names, without the sign or a lone 1. */
export function diceText(dice: string, where: string): { gain: boolean; text: string } {
  const match = /^([+-]?)(\d*)d(\d+)$/.exec(dice)
  if (match === null) {
    throw new UnwordableEffect(`${where}: the dice "${dice}" have no paper wording.`)
  }
  const count = match[2] === '1' || match[2] === '' ? '' : (match[2] ?? '')
  return { gain: match[1] !== '-', text: `${count}d${match[3] ?? ''}` }
}

/**
 * The effect of a band as the tokens a card writes: "Winter +1", "lose d6 Wind", "regain 1 Nerve".
 * Throws an `UnwordableEffect` for what has no wording.
 */
export function outcomeToken(
  trackers: readonly ReleaseTracker[],
  effect: OutcomeEffect,
  where: string,
): string {
  if (effect.kind === 'other') {
    throw new UnwordableEffect(
      `${where}: a "${effect.name}" effect in a check outcome has no paper wording.`,
    )
  }
  if (effect.kind === 'tracker') {
    const tracker = trackers.find((candidate) => candidate.key === effect.tracker)
    if (tracker === undefined || effect.op !== 'add') {
      throw new UnwordableEffect(
        `${where}: a ${effect.op} effect on "${effect.tracker}" has no paper wording.`,
      )
    }
    return `${tracker.name} ${effect.value < 0 ? '-' : '+'}${String(Math.abs(effect.value))}`
  }
  const field = capitalised(effect.field)
  if (effect.op !== 'add' && effect.op !== 'subtract') {
    throw new UnwordableEffect(
      `${where}: a ${effect.op} effect on "${effect.field}" has no paper wording.`,
    )
  }
  if (typeof effect.value === 'string') {
    const { gain, text } = diceText(effect.value, where)
    return `${gain === (effect.op === 'add') ? 'regain' : 'lose'} ${text} ${field}`
  }
  const gain = (effect.op === 'add') === effect.value >= 0
  return `${gain ? 'regain' : 'lose'} ${String(Math.abs(effect.value))} ${field}`
}

/**
 * The effect of a table entry in words: "Doom +1", "Next scene: Road", "Start a Thread: The lamp".
 * Throws an `UnwordableEffect` for what has no wording (an `other` effect, a tracker change that is
 * not an add, unless `allowSet`: "Set Doom to 3"), and an `Error` for a Scene Type the release does
 * not have.
 */
export function entryEffectText(
  release: Pick<Release, 'trackers' | 'sceneTypes'>,
  effect: EntryEffect,
  where: string,
  allowSet = false,
): string {
  if (effect.kind === 'tracker') {
    const tracker = release.trackers.find((candidate) => candidate.key === effect.tracker)
    if (tracker !== undefined && allowSet && effect.op === 'set') {
      return `Set ${tracker.name} to ${String(effect.value)}`
    }
    if (tracker === undefined || effect.op !== 'add') {
      throw new UnwordableEffect(
        `${where}: a ${effect.op} effect on "${effect.tracker}" has no paper wording.`,
      )
    }
    return `${tracker.name} ${effect.value < 0 ? '-' : '+'}${String(Math.abs(effect.value))}`
  }
  if (effect.kind === 'nextScene') {
    const sceneType = release.sceneTypes.find((candidate) => candidate.key === effect.sceneType)
    if (sceneType === undefined) {
      throw new Error(
        `${where} names the Scene Type "${effect.sceneType}", which the release does not have.`,
      )
    }
    return `Next scene: ${sceneType.name}`
  }
  if (effect.kind === 'createThread') {
    return `Start a Thread: ${effect.name}`
  }
  throw new UnwordableEffect(`${where}: a ${effect.name} effect has no paper wording.`)
}
