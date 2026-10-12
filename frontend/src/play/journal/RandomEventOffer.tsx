import { useState } from 'react'

import { Button } from '@/shared/ui/button'

import type { Campaign } from '../campaigns/useCampaigns'
import { useRecordOracleTableResult, type JournalEntry } from './useJournal'

/**
 * "Roll <table>" for the random event a fate question answered with a double: it rolls the table
 * the oracle declares through the same request as the oracle panel, which records a new entry.
 * Only offered in the current scene, where an entry can be recorded. After a successful roll the
 * button gives way to a plain "Rolled" mark, which guards against a second click only while this
 * offer stays mounted: the mark is not stored, so a remount (switching between the journal and
 * focus views, or reloading the page) offers the roll again.
 */
export function RandomEventOffer({
  campaignId,
  entry,
  tables,
}: {
  campaignId: string
  entry: JournalEntry
  /** The oracle tables of the campaign's pinned release, which name the table to roll. */
  tables: Campaign['oracleTables']
}) {
  const roll = useRecordOracleTableResult(campaignId)
  const [rolled, setRolled] = useState(false)
  if (entry.content.kind !== 'likelihood' || entry.content.randomEvent === null) {
    return null
  }
  const tableKey = entry.content.randomEvent
  const table = tables.find(({ key }) => key === tableKey)

  if (rolled) {
    return <p className="text-sm text-muted-foreground">Rolled {table?.name ?? tableKey}</p>
  }

  return (
    <div className="space-y-1">
      <Button
        type="button"
        variant="outline"
        size="sm"
        disabled={roll.isPending}
        onClick={() => {
          roll.mutate(tableKey, {
            onSuccess: () => {
              setRolled(true)
            },
          })
        }}
      >
        Roll {table?.name ?? tableKey}
      </Button>
      {roll.error && (
        <p role="alert" className="text-sm text-destructive">
          {roll.error.message}
        </p>
      )}
    </div>
  )
}
