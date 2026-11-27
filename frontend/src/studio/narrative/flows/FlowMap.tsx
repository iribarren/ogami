import { useMemo } from 'react'

import { Alert } from '@/shared/ui/alert'
import { FlowDiagramView, type DiagramActivation } from '@/shared/flowDiagram'

import { useGameSystemOracles } from '../../oracles/useGameSystemOracles'
import type { GameSystemNarrative } from '../useGameSystemNarrative'
import type { FlowDraft } from './flowDraft'
import type { FlowAnswer } from './flowError'
import { flowDiagramFromDraft, mergeIssues } from './flowMapDiagram'
import { answerIssues, mapTarget, selectedMapId, type MapTarget } from './flowMapNodes'
import type { FlowNode } from './flowNode'

/**
 * The Map tab: the draft as the flow diagram, unsaved edits included, with what is wrong on the
 * nodes it belongs to. It reads the Scene Types of the narrative and the oracle tables of the draft;
 * activating a node hands its target to `onOpen`, which shows the panel or the Scene Type page.
 */
export function FlowMap({
  gameSystemKey,
  draft,
  narrative,
  answer,
  node,
  onOpen,
}: {
  gameSystemKey: string
  draft: FlowDraft
  narrative: GameSystemNarrative
  /** What the last refused save said: it is shown on the nodes it is about. */
  answer: FlowAnswer
  /** The node the outline has selected, drawn as selected. */
  node: FlowNode
  onOpen: (target: MapTarget) => void
}) {
  const oracles = useGameSystemOracles(gameSystemKey)
  const tables = oracles.data?.tables
  const map = useMemo(
    () => flowDiagramFromDraft(draft, narrative.sceneTypes, tables ?? []),
    [draft, narrative.sceneTypes, tables],
  )

  if (oracles.isPending) {
    return <p className="text-muted-foreground">Loading the map…</p>
  }
  const issues = mergeIssues(map.issues, answerIssues(draft, map.phaseKeys, answer, map.drawn))
  const open = (id: string, kind: DiagramActivation) => {
    const target = mapTarget(id, kind, map.phaseKeys, map.diagram)
    if (target !== null) {
      onOpen(target)
    }
  }

  return (
    <div className="space-y-3">
      {oracles.isError && (
        <Alert>Couldn’t load the oracle tables, so every table is marked as missing.</Alert>
      )}
      {map.flowIssues.length > 0 && (
        <Alert>
          <ul className="list-disc pl-5">
            {map.flowIssues.map((message) => (
              <li key={message}>{message}</li>
            ))}
          </ul>
        </Alert>
      )}
      <p className="text-sm text-muted-foreground">
        The flow as it is in this form, saved or not. Pick a phase, a selection or a hook to edit
        it; a Scene Type opens its own page.
      </p>
      <div className="overflow-x-auto">
        <FlowDiagramView
          diagram={map.diagram}
          issues={issues}
          selectedId={selectedMapId(draft, map.phaseKeys, node)}
          onActivate={open}
        />
      </div>
    </div>
  )
}
