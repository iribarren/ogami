import type { SheetTemplateView } from '@/shared/sheet/types'

import type { Campaign } from '../campaigns/useCampaigns'

/** The sheet of a campaign's pinned release in the neutral shape the shared sheet components read. */
export function sheetTemplate({
  sheet,
  sheetLayout,
}: Campaign['pinnedRelease']): SheetTemplateView {
  return { fields: sheet, layout: sheetLayout }
}
