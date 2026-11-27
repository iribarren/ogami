import type { OutcomeEffect, ReleaseTracker } from './release'

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
