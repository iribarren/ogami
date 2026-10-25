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

/** Whether the entry is a pool roll that was not pushed yet, has a die that failed, and its check declares a push. */
export function canPush(campaign: Campaign, entry: JournalEntry) {
  const pool = entry.content.kind === 'check' ? entry.content.pool : null
  return (
    pool !== null &&
    pool.push === null &&
    pool.successes < pool.size &&
    pushOf(campaign, entry) !== null
  )
}
