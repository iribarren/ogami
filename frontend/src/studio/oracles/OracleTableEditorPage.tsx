import { useState } from 'react'
import { Link } from '@tanstack/react-router'
import { ArrowLeft, Eye } from 'lucide-react'

import { stepContext } from '../narrative/steps/stepContext'
import { useGameSystemNarrative } from '../narrative/useGameSystemNarrative'
import { EditorFrame } from '../EditorFrame'
import { StudioError } from '../useGameSystems'
import { TableEditor } from './TableEditor'
import { useRollHistory } from './useRollHistory'
import {
  useGameSystemOracles,
  type OracleTableItem,
  type OracleTableResult,
} from './useGameSystemOracles'

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
  const history = useRollHistory<OracleTableResult>()
  // What the form was filled from, once: later reads (a save, a refetch) must not replace it.
  const [opened, setOpened] = useState<{
    key: string
    item: OracleTableItem
    version: number
  } | null>(null)
  // The key a save is renaming the table to: the cache answers with it before the URL does.
  const [renaming, setRenaming] = useState<string | null>(null)

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

  // Cached data older than the stale time is being read again: wait for it rather than fill the
  // form from what may lack entries saved elsewhere. If that read fails, `isFetching` ends and the
  // form opens from the cache; a save then answers with a conflict. A form already open stays.
  if (oracles.isFetching && opened?.key !== table) {
    return <p className="text-muted-foreground">Loading the table…</p>
  }
  const find = (key: string) => oracles.data.tables.find(({ definition }) => definition.key === key)
  // Until the URL follows the rename, the editor stays on the renamed table, mounted.
  const found = find(table) ?? (renaming === null ? undefined : find(renaming))
  // The form is filled once, from what was read when it opened (after any refetch in flight, see
  // above); it must not vanish when the table has gone from a later read: a save then says so.
  if (found !== undefined && (opened?.item !== found || opened.key !== table)) {
    setOpened({ key: table, item: found, version: oracles.data.version })
  }
  const open = opened?.key === table ? opened : null
  const item = found ?? open?.item
  if (!item) {
    return (
      <div className="space-y-3">
        <p role="alert">The draft has no oracle table “{table}”.</p>
        {backLink}
      </div>
    )
  }

  return (
    <EditorFrame
      gameSystemKey={gameSystemKey}
      section="oracles"
      kind="Oracle table"
      icon={<Eye />}
      title={item.definition.name}
      backLink={backLink}
    >
      {open === null ? (
        <p className="text-muted-foreground">Loading the table…</p>
      ) : (
        <TableEditor
          key={table}
          gameSystemKey={gameSystemKey}
          oracles={oracles.data}
          context={withNarrative && narrative.data ? stepContext(narrative.data) : null}
          initial={open.item.definition}
          version={open.version}
          history={history}
          onRenaming={setRenaming}
        />
      )}
    </EditorFrame>
  )
}
