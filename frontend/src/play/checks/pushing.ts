import type { Campaign } from '../campaigns/useCampaigns'
import type { JournalEntry } from '../journal/useJournal'

/** The pinned check of a pool entry, when it declares a push. */
export function pushOf(campaign: Campaign, entry: JournalEntry) {
  const { content } = entry
  if (content.kind !== 'check') {
    return null
  }
  return campaign.checks.find(({ key }) => key === content.checkKey)?.push ?? null
}

type Pool = NonNullable<Extract<JournalEntry['content'], { kind: 'check' }>['pool']>

/** How many regular dice failed: the only ones a push re-rolls, never the Hunger dice (ADR 0044). */
export function failedRegularDice(pool: Pool) {
  return pool.dice.filter(({ group, success }) => group === 'pool' && !success).length
}

/**
 * Whether a check roll already succeeded, as far as the entry tells. A critical, or a total that
 * reaches the difficulty that applied (ADR 0044 reads a failed roll the same way). A check with no
 * difficulty has no flag to read: its bands are not in the entry, so it counts as not known.
 */
export function rollSucceeded(content: Extract<JournalEntry['content'], { kind: 'check' }>) {
  if (content.special === 'critical' || content.special === 'messyCritical') {
    return true
  }
  return content.difficulty !== null && content.total >= content.difficulty
}

/** Whether the roll has Hunger dice. */
export function hasHungerDice(pool: Pool) {
  return pool.dice.some(({ group }) => group === 'hunger')
}

/** The score a burn gives: the momentum, at most `scoreMax` when the check has one (ADR 0049). */
export function burnScore(momentum: number, scoreMax: number | null) {
  return scoreMax === null ? momentum : Math.min(momentum, scoreMax)
}

/** Whether a score beats a challenge die: it must show strictly less, as ties go to the dice. */
export function isBeatenBy(die: { face: number }, score: number) {
  return die.face < score
}

/** How many challenge dice a score beats. */
export function diceBeatenBy(dice: readonly { face: number }[], score: number) {
  return dice.filter((die) => isBeatenBy(die, score)).length
}

/**
 * Whether the entry is a challenge roll that was not burned yet, whose check declares a push and
 * names a momentum, and whose momentum when rolled would beat more dice than the score did. It
 * mirrors the offer rule of the backend, and its guard on the pinned check (dice count, sides and
 * cap must still be those of the roll, as `CheckPusher` compares); negative or zero momentum beats
 * nothing. The momentum now
 * may differ: the offer tells it, and the API checks it again.
 */
export function canBurn(campaign: Campaign, entry: JournalEntry) {
  const { content } = entry
  if (content.kind !== 'check' || content.challenge === null) {
    return false
  }
  const { challenge } = content
  const pinned = campaign.checks.find(({ key }) => key === content.checkKey)
  if (
    challenge.burn !== null ||
    challenge.momentum === null ||
    (pinned?.push ?? null) === null ||
    (pinned?.challenge?.momentum ?? null) === null ||
    pinned?.challenge?.count !== challenge.dice.length ||
    pinned.challenge.sides !== challenge.sides ||
    pinned.challenge.scoreMax !== challenge.scoreMax
  ) {
    return false
  }
  return (
    diceBeatenBy(challenge.dice, burnScore(challenge.momentum, challenge.scoreMax)) >
    diceBeatenBy(challenge.dice, challenge.score)
  )
}

/**
 * Whether the entry offers a push or a burn. A pool roll that was not pushed yet, with a regular
 * die that failed, whose check declares a push; a roll with a Hunger die only failing has nothing
 * to re-roll. Or a challenge roll the momentum can improve (`canBurn`).
 */
export function canPush(campaign: Campaign, entry: JournalEntry) {
  const pool = entry.content.kind === 'check' ? entry.content.pool : null
  return (
    (pool !== null &&
      pool.push === null &&
      failedRegularDice(pool) > 0 &&
      pushOf(campaign, entry) !== null) ||
    canBurn(campaign, entry)
  )
}
