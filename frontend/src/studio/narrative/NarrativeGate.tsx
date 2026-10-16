import type { UseQueryResult } from '@tanstack/react-query'
import type { ReactNode } from 'react'

import { StudioError } from '../useGameSystems'
import type { GameSystemNarrative } from './useGameSystemNarrative'

/**
 * What a narrative tab shows before it has a draft to list: loading, why the narrative could not
 * be read, the schema version 1 message. With a schema 2 narrative it shows `children` and, above
 * them, a failed refresh (the stale data stays on screen).
 */
export function NarrativeGate({
  narrative,
  children,
}: {
  narrative: UseQueryResult<GameSystemNarrative>
  children: (data: GameSystemNarrative) => ReactNode
}) {
  if (narrative.data === undefined) {
    if (narrative.isPending) {
      return <p className="text-muted-foreground">Loading the narrative…</p>
    }
    return (
      <p role="alert" className="text-sm text-destructive">
        {narrative.error instanceof StudioError && narrative.error.status === 404
          ? 'This GameSystem does not exist, or it is not one of yours.'
          : narrative.error.message}
      </p>
    )
  }

  if (narrative.data.schemaVersion < 2) {
    return (
      <p className="text-muted-foreground">
        Schema version 1 drafts have no narrative sections: trackers, tags and fact slots exist only
        in schema version 2 drafts.
      </p>
    )
  }

  return (
    <>
      {narrative.isRefetchError && (
        <p role="alert" className="text-sm text-destructive">
          Couldn&apos;t refresh the narrative. {narrative.error.message}
        </p>
      )}
      {children(narrative.data)}
    </>
  )
}
