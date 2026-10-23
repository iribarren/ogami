import { ArrowLeft, Route as RouteIcon } from 'lucide-react'
import { useState } from 'react'
import { Link } from '@tanstack/react-router'

import { Badge } from '@/shared/ui/badge'
import { IconTile } from '@/shared/ui/icon-tile'

import { StudioError, useGameSystemValidation, useStudioGameSystem } from '../../useGameSystems'
import { useGameSystemNarrative, type NarrativeItem } from '../useGameSystemNarrative'
import { FlowEditor } from './FlowEditor'
import { flowToDraft, type FlowDraft } from './flowDraft'
import { flowIssueCount } from './flowIssues'

/**
 * `/studio/game-systems/$key/flows/$flow`: edits one flow of a draft. `node` is the outline node
 * the URL selects (the `node` search param); anything it does not name opens the flow settings.
 */
export function FlowEditorPage({
  gameSystemKey,
  flow,
  node,
}: {
  gameSystemKey: string
  flow: string
  node?: string
}) {
  const narrative = useGameSystemNarrative(gameSystemKey)
  const gameSystem = useStudioGameSystem(gameSystemKey)
  const validation = useGameSystemValidation(gameSystemKey)
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
      className="inline-flex items-center gap-1.5 text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
    >
      <ArrowLeft aria-hidden="true" className="size-4" />
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
  // A flow that has gone from the draft has no position: it has no issues to count.
  const position = data.flows.findIndex((item) => item.key === flow)
  const issues = validation.data && position >= 0 ? flowIssueCount(validation.data, position) : 0

  return (
    <div className="mx-auto w-full max-w-[1232px] space-y-6">
      {backLink}
      <header className="flex flex-wrap items-center gap-4">
        <IconTile size="lg">
          <RouteIcon />
        </IconTile>
        <div className="min-w-0 flex-1">
          <p className="text-xs text-muted-foreground">
            {gameSystem.data?.name ?? gameSystemKey} · Flow
          </p>
          <h1 className="font-heading text-[26px] leading-tight font-bold break-words">
            {typeof name === 'string' && name !== '' ? name : shown.key}
          </h1>
        </div>
        {shown.definition.default === true && <Badge variant="secondary">Default</Badge>}
        {issues > 0 && (
          <Badge variant="warning">{`${String(issues)} ${issues === 1 ? 'issue' : 'issues'}`}</Badge>
        )}
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
          selected={node}
        />
      )}
    </div>
  )
}
