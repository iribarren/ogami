import { useState } from 'react'
import { Link } from '@tanstack/react-router'

import { StudioError } from '../useGameSystems'
import { LikelihoodOracleEditor } from './LikelihoodOracleEditor'
import { useGameSystemOracles } from './useGameSystemOracles'

/** `/studio/game-systems/$key/likelihood-oracles/$oracle`: edits one likelihood oracle of a draft. */
export function LikelihoodOracleEditorPage({
  gameSystemKey,
  oracle,
}: {
  gameSystemKey: string
  oracle: string
}) {
  const oracles = useGameSystemOracles(gameSystemKey)
  const [reloads, setReloads] = useState(0)
  const [reloadFailed, setReloadFailed] = useState(false)
  // The key a save is renaming the oracle to: the cache answers with it before the URL does.
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
      return <p className="text-muted-foreground">Loading the oracle…</p>
    }
    return (
      <p role="alert" className="text-sm text-destructive">
        {oracles.error instanceof StudioError && oracles.error.status === 404
          ? 'This GameSystem does not exist, or it is not one of yours.'
          : oracles.error.message}
      </p>
    )
  }

  const find = (key: string) =>
    oracles.data.likelihood.find(({ definition }) => definition.key === key)
  // Until the URL follows the rename, the editor stays on the renamed oracle, mounted.
  const item = find(oracle) ?? (renaming === null ? undefined : find(renaming))
  if (!item) {
    return (
      <div className="space-y-3">
        <p role="alert">The draft has no likelihood oracle “{oracle}”.</p>
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
      <LikelihoodOracleEditor
        key={`${oracle}-${String(reloads)}`}
        gameSystemKey={gameSystemKey}
        oracles={oracles.data}
        item={item}
        onRenaming={setRenaming}
        onReload={() => {
          setReloadFailed(false)
          void oracles.refetch().then((result) => {
            // A failed refetch keeps the stale oracle: do not reset the form on it.
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
