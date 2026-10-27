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

/** Whether the roll has Hunger dice. */
export function hasHungerDice(pool: Pool) {
  return pool.dice.some(({ group }) => group === 'hunger')
}

/**
 * Whether the entry is a pool roll that was not pushed yet, has a regular die that failed, and its
 * check declares a push. A roll whose only failed dice are Hunger dice has nothing to re-roll.
 */
export function canPush(campaign: Campaign, entry: JournalEntry) {
  const pool = entry.content.kind === 'check' ? entry.content.pool : null
  return (
    pool !== null &&
    pool.push === null &&
    failedRegularDice(pool) > 0 &&
    pushOf(campaign, entry) !== null
  )
}
