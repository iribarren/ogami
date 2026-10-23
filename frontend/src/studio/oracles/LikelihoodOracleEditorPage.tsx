import { useState } from 'react'
import { Link } from '@tanstack/react-router'
import { ArrowLeft, Eye } from 'lucide-react'

import { useGameSystemNarrative } from '../narrative/useGameSystemNarrative'
import { EditorFrame } from '../EditorFrame'
import { StudioError } from '../useGameSystems'
import { LikelihoodOracleEditor } from './LikelihoodOracleEditor'
import { useRollHistory } from './useRollHistory'
import { useGameSystemOracles, type LikelihoodTest } from './useGameSystemOracles'

/** `/studio/game-systems/$key/likelihood-oracles/$oracle`: edits one likelihood oracle of a draft. */
export function LikelihoodOracleEditorPage({
  gameSystemKey,
  oracle,
}: {
  gameSystemKey: string
  oracle: string
}) {
  const oracles = useGameSystemOracles(gameSystemKey)
  const narrative = useGameSystemNarrative(gameSystemKey)
  const [reloads, setReloads] = useState(0)
  const [reloadFailed, setReloadFailed] = useState(false)
  // The key a save is renaming the oracle to: the cache answers with it before the URL does.
  const [renaming, setRenaming] = useState<string | null>(null)
  const history = useRollHistory<LikelihoodTest>()

  const backLink = (
    <Link
      to="/studio/game-systems/$key"
      params={{ key: gameSystemKey }}
      className="inline-flex items-center gap-1.5 text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
    >
      <ArrowLeft aria-hidden="true" className="size-4" />
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

  // A version 2 chaos factor picks its tracker from the narrative, so it is read first.
  const withNarrative = oracles.data.schemaVersion >= 2
  if (withNarrative && narrative.data === undefined) {
    return narrative.isPending ? (
      <p className="text-muted-foreground">Loading the oracle…</p>
    ) : (
      <p role="alert" className="text-sm text-destructive">
        {narrative.error.message}
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
    <EditorFrame
      gameSystemKey={gameSystemKey}
      section="oracles"
      kind="Likelihood oracle"
      icon={<Eye />}
      title={item.definition.name}
      backLink={backLink}
    >
      <LikelihoodOracleEditor
        key={`${oracle}-${String(reloads)}`}
        gameSystemKey={gameSystemKey}
        oracles={oracles.data}
        trackers={withNarrative && narrative.data ? narrative.data.trackers : null}
        item={item}
        history={history}
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
    </EditorFrame>
  )
}
