import type { Release, ReleaseLogEntry } from './release'

/** Blank lines under each Tag: room for a few names. */
export const TAG_LINES = 4

/** What the campaign log asks to write down: a line per Fact slot, a few per Tag. */
export interface CampaignLog {
  slots: ReleaseLogEntry[]
  tags: ReleaseLogEntry[]
  tagLines: number
}

/** The campaign log of a release. It is always printed: with no Fact slots and no Tags it is the ruled lines. */
export function buildCampaignLog(release: Release): CampaignLog {
  return { slots: release.factSlots, tags: release.tags, tagLines: TAG_LINES }
}
