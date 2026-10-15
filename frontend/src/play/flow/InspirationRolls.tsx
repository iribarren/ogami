import { useId, useState } from 'react'

import { Button } from '@/shared/ui/button'

import type { Campaign } from '../campaigns/useCampaigns'
import { useRecordOracleTableResult } from '../journal/useJournal'

/**
 * The oracle tables a prompt step offers as inspiration: one button each, named after the table of
 * the campaign (a key it does not have is skipped). A roll is recorded in the journal like the
 * Oracle panel's, and the latest result of each table shows under the buttons. The player still
 * writes the answer.
 */
export function InspirationRolls({ campaign, keys }: { campaign: Campaign; keys: string[] }) {
  const roll = useRecordOracleTableResult(campaign.id)
  const [results, setResults] = useState<Record<string, string>>({})
  const headingId = useId()
  const tables = keys.flatMap((key) => campaign.oracleTables.filter((table) => table.key === key))

  if (tables.length === 0) {
    return null
  }

  return (
    <div className="space-y-2">
      <p id={headingId} className="text-sm font-medium">
        Inspiration
      </p>
      <div className="flex flex-wrap gap-2" role="group" aria-labelledby={headingId}>
        {tables.map((table) => (
          <Button
            key={table.key}
            type="button"
            variant="outline"
            size="sm"
            aria-label={`Roll on ${table.name}`}
            disabled={roll.isPending}
            onClick={() => {
              roll.mutate(table.key, {
                onSuccess: ({ content }) => {
                  if (content.kind === 'oracle-table') {
                    const text = content.steps
                      .map((step) => step.text)
                      .filter((part) => part !== '')
                      .join(' › ')
                    setResults((latest) => ({ ...latest, [table.key]: text }))
                  }
                },
              })
            }}
          >
            {table.name}
          </Button>
        ))}
      </div>
      {tables.some((table) => results[table.key] !== undefined) && (
        <ul aria-label="Inspiration results" className="space-y-1 text-sm">
          {tables
            .filter((table) => results[table.key] !== undefined)
            .map((table) => (
              <li key={table.key}>
                <span className="text-muted-foreground">{table.name}: </span>
                <span className="font-semibold">{results[table.key]}</span>
              </li>
            ))}
        </ul>
      )}
      {roll.error && (
        <p role="alert" className="text-sm text-destructive">
          {roll.error.message}
        </p>
      )}
    </div>
  )
}
