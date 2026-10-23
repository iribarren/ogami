import { useState } from 'react'
import { Link } from '@tanstack/react-router'
import { ArrowLeft, Clapperboard } from 'lucide-react'

import { EditorFrame } from '../../EditorFrame'
import { StudioError } from '../../useGameSystems'
import { useGameSystemNarrative, type NarrativeItem } from '../useGameSystemNarrative'
import { SceneTypeEditor } from './SceneTypeEditor'
import { sceneTypeToDraft, type SceneTypeDraft } from './sceneTypeDraft'

/** `/studio/game-systems/$key/scene-types/$sceneType`: edits one Scene Type of a draft. */
export function SceneTypeEditorPage({
  gameSystemKey,
  sceneType,
}: {
  gameSystemKey: string
  sceneType: string
}) {
  const narrative = useGameSystemNarrative(gameSystemKey)
  const [opened, setOpened] = useState<{
    key: string
    item: NarrativeItem
    draft: SceneTypeDraft
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
      return <p className="text-muted-foreground">Loading the Scene Type…</p>
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
  const found = data.sceneTypes.find((item) => item.key === sceneType)
  const draft = found === undefined ? null : sceneTypeToDraft(found.definition)
  // The form is filled once, from what was read when it opened; later reads (a save, a refetch)
  // must not take it from under the person, nor make it vanish when the Scene Type has gone or
  // changed shape elsewhere: a save then says so, and a reload explains why it cannot replace it.
  if (found !== undefined && draft !== null && opened?.item !== found) {
    setOpened({ key: sceneType, item: found, draft, version: data.version })
  }
  const open = opened?.key === sceneType ? opened : null
  const shown = found ?? open?.item
  if (shown === undefined) {
    return (
      <div className="space-y-3">
        <p role="alert">The draft has no Scene Type “{sceneType}”.</p>
        {backLink}
      </div>
    )
  }
  const name = shown.definition.name

  return (
    <EditorFrame
      gameSystemKey={gameSystemKey}
      section="sceneTypes"
      kind="Scene Type"
      icon={<Clapperboard />}
      title={typeof name === 'string' && name !== '' ? name : shown.key}
      backLink={backLink}
    >
      {open === null ? (
        <p role="alert">
          The Scene Type “{shown.key}” has a shape this editor does not know; it stays as it is
          until it is deleted.
        </p>
      ) : (
        <SceneTypeEditor
          key={sceneType}
          gameSystemKey={gameSystemKey}
          narrative={data}
          original={open.item.key}
          initial={open.draft}
          version={open.version}
        />
      )}
    </EditorFrame>
  )
}
