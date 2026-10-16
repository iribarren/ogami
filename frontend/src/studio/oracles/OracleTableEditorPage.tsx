import { useState } from 'react'
import { Link } from '@tanstack/react-router'

import { StudioError } from '../useGameSystems'
import { TableEditor } from './TableEditor'
import { useRollHistory } from './useRollHistory'
import { useGameSystemOracles } from './useGameSystemOracles'

/** `/studio/game-systems/$key/oracle-tables/$table`: edits one oracle table of a draft. */
export function OracleTableEditorPage({
  gameSystemKey,
  table,
}: {
  gameSystemKey: string
  table: string
}) {
  const oracles = useGameSystemOracles(gameSystemKey)
  const [reloads, setReloads] = useState(0)
  const history = useRollHistory()

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

  const item = oracles.data.tables.find(({ definition }) => definition.key === table)
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
        item={item}
        history={history}
        onReload={() => {
          void oracles.refetch().then((result) => {
            // A failed refetch keeps the stale table: do not reset the form on it.
            if (!result.isError) {
              setReloads((count) => count + 1)
            }
          })
        }}
      />
    </div>
  )
}
