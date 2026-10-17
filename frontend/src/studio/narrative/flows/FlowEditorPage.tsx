import { useState } from 'react'
import { Link } from '@tanstack/react-router'

import { StudioError } from '../../useGameSystems'
import { useGameSystemNarrative, type NarrativeItem } from '../useGameSystemNarrative'
import { FlowEditor } from './FlowEditor'
import { flowToDraft, type FlowDraft } from './flowDraft'

/** `/studio/game-systems/$key/flows/$flow`: edits one flow of a draft. */
export function FlowEditorPage({ gameSystemKey, flow }: { gameSystemKey: string; flow: string }) {
  const narrative = useGameSystemNarrative(gameSystemKey)
  const [opened, setOpened] = useState<{
    key: string
    item: NarrativeItem
    draft: FlowDraft
    version: number
  } | null>(null)

  const backLink = (
    <Link
      to="/studio/game-systems/$key"
      params={{ key: gameSystemKey }}
      className="text-sm underline-offset-4 hover:underline"
    >
      Back to {gameSystemKey}
    </Link>
  )

  if (narrative.data === undefined) {
    if (narrative.isPending) {
      return <p className="text-muted-foreground">Loading the flow…</p>
    }
    return (
      <p role="alert" className="text-sm text-destructive">
        {narrative.error instanceof StudioError && narrative.error.status === 404
          ? 'This GameSystem does not exist, or it is not one of yours.'
          : narrative.error.message}
      </p>
    )
  }

  const { data } = narrative
  const found = data.flows.find((item) => item.key === flow)
  const draft = found === undefined ? null : flowToDraft(found.definition)
  // The form is filled once, from what was read when it opened; later reads (a save, a refetch)
  // must not take it from under the person, nor make it vanish when the flow has gone or
  // changed shape elsewhere: a save then says so, and a reload explains why it cannot replace it.
  if (found !== undefined && draft !== null && opened?.item !== found) {
    setOpened({ key: flow, item: found, draft, version: data.version })
  }
  const open = opened?.key === flow ? opened : null
  const shown = found ?? open?.item
  if (shown === undefined) {
    return (
      <div className="space-y-3">
        <p role="alert">The draft has no flow “{flow}”.</p>
        {backLink}
      </div>
    )
  }
  const name = shown.definition.name

  return (
    <div className="space-y-6">
      <header className="space-y-2">
        {backLink}
        <h1 className="font-heading text-page-title break-words">
          {typeof name === 'string' && name !== '' ? name : shown.key}
        </h1>
      </header>
      {open === null ? (
        <p role="alert">
          The flow “{shown.key}” has a shape this editor does not know; it stays as it is until it
          is deleted.
        </p>
      ) : (
        <FlowEditor
          key={flow}
          gameSystemKey={gameSystemKey}
          narrative={data}
          original={open.item.key}
          initial={open.draft}
          version={open.version}
        />
      )}
    </div>
  )
}
