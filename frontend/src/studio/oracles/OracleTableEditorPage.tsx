import { useState } from 'react'
import { Link } from '@tanstack/react-router'

import { stepContext } from '../narrative/steps/stepContext'
import { useGameSystemNarrative } from '../narrative/useGameSystemNarrative'
import { StudioError } from '../useGameSystems'
import { TableEditor } from './TableEditor'
import { useRollHistory } from './useRollHistory'
import { useGameSystemOracles, type OracleTableResult } from './useGameSystemOracles'

/** `/studio/game-systems/$key/oracle-tables/$table`: edits one oracle table of a draft. */
export function OracleTableEditorPage({
  gameSystemKey,
  table,
}: {
  gameSystemKey: string
  table: string
}) {
  const oracles = useGameSystemOracles(gameSystemKey)
  const narrative = useGameSystemNarrative(gameSystemKey)
  const [reloads, setReloads] = useState(0)
  const history = useRollHistory<OracleTableResult>()
  const [reloadFailed, setReloadFailed] = useState(false)
  // The key a save is renaming the table to: the cache answers with it before the URL does.
  const [renaming, setRenaming] = useState<string | null>(null)

  const backLink = (
    <Link
      to="/studio/game-systems/$key"
      params={{ key: gameSystemKey }}
      className="text-sm underline-offset-4 hover:underline"
    >
      Back to {gameSystemKey}
    </Link>
  )

  if (oracles.data === undefined) {
    if (oracles.isPending) {
      return <p className="text-muted-foreground">Loading the table…</p>
    }
    return (
      <p role="alert" className="text-sm text-destructive">
        {oracles.error instanceof StudioError && oracles.error.status === 404
          ? 'This GameSystem does not exist, or it is not one of yours.'
          : oracles.error.message}
      </p>
    )
  }

  // A version 2 entry picks its Scene Type and effects from the narrative, so it is read first.
  const withNarrative = oracles.data.schemaVersion >= 2
  if (withNarrative && narrative.data === undefined) {
    return narrative.isPending ? (
      <p className="text-muted-foreground">Loading the table…</p>
    ) : (
      <p role="alert" className="text-sm text-destructive">
        {narrative.error.message}
      </p>
    )
  }

  const find = (key: string) => oracles.data.tables.find(({ definition }) => definition.key === key)
  // Until the URL follows the rename, the editor stays on the renamed table, mounted.
  const item = find(table) ?? (renaming === null ? undefined : find(renaming))
  if (!item) {
    return (
      <div className="space-y-3">
        <p role="alert">The draft has no oracle table “{table}”.</p>
        {backLink}
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <header className="space-y-2">
        {backLink}
        <h1 className="font-heading text-page-title break-words">{item.definition.name}</h1>
      </header>
      <TableEditor
        key={`${table}-${String(reloads)}`}
        gameSystemKey={gameSystemKey}
        oracles={oracles.data}
        context={withNarrative && narrative.data ? stepContext(narrative.data) : null}
        item={item}
        history={history}
        onRenaming={setRenaming}
        onReload={() => {
          setReloadFailed(false)
          void oracles.refetch().then((result) => {
            // A failed refetch keeps the stale table: do not reset the form on it.
            if (result.isError) {
              setReloadFailed(true)
            } else {
              setReloads((count) => count + 1)
            }
          })
        }}
      />
      {reloadFailed && (
        <p role="alert" className="text-sm text-destructive">
          Couldn&apos;t reload the latest version. Try again.
        </p>
      )}
    </div>
  )
}
