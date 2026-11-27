import { useMemo, useState } from 'react'

import { Alert } from '@/shared/ui/alert'
import { Button } from '@/shared/ui/button'
import {
  FlowDiagramView,
  useDiagramDrag,
  type DiagramActivation,
  type DiagramDrop,
} from '@/shared/flowDiagram'

import { useGameSystemOracles } from '../../oracles/useGameSystemOracles'
import type { GameSystemNarrative } from '../useGameSystemNarrative'
import type { FlowDraft } from './flowDraft'
import type { FlowAnswer } from './flowError'
import { flowDiagramFromDraft, mergeIssues } from './flowMapDiagram'
import {
  isRefusal,
  movePhase,
  phaseLabel,
  toggleMode,
  type MapEdit,
  type MapResult,
} from './flowMapEdits'
import { MapActionBar } from './MapActionBar'
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
  locked,
  onOpen,
  onEdit,
}: {
  gameSystemKey: string
  draft: FlowDraft
  narrative: GameSystemNarrative
  /** What the last refused save said: it is shown on the nodes it is about. */
  answer: FlowAnswer
  /** The node the outline has selected, drawn as selected. */
  node: FlowNode
  /** A save is on its way: the draft cannot be edited from the Map. */
  locked: boolean
  onOpen: (target: MapTarget) => void
  /** Applies an edit to the draft, the way the outline's own buttons do. */
  onEdit: (edit: MapEdit) => void
}) {
  const oracles = useGameSystemOracles(gameSystemKey)
  const tables = oracles.data?.tables
  const map = useMemo(
    () => flowDiagramFromDraft(draft, narrative.sceneTypes, tables ?? []),
    [draft, narrative.sceneTypes, tables],
  )

  // The node last focused or pressed on the map, which the action bar acts on.
  const [focused, setFocused] = useState<string | null>(null)
  // What the last edit did, or why it was refused: one live region for every gesture.
  const [said, setSaid] = useState('')
  const phaseOf = (key: string) => map.phaseKeys.indexOf(key)

  const apply = (result: MapResult) => {
    if (isRefusal(result)) {
      setSaid(result.refused)
      return
    }
    onEdit(result)
    setSaid(result.say)
  }
  const drag = useDiagramDrag({
    layout: map.layout,
    canDrag: (id) => !locked && id.startsWith('phase:') && phaseOf(id.slice('phase:'.length)) >= 0,
    onDrop: ({ sourceId, hit }: DiagramDrop) => {
      const from = phaseOf(sourceId.slice('phase:'.length))
      const to = hit?.phase == null ? -1 : phaseOf(hit.phase)
      apply(
        to < 0
          ? { refused: `Dropped outside every phase: ${phaseLabel(draft.phases[from])} stays.` }
          : movePhase(draft.phases, from, to),
      )
    },
  })

  if (oracles.isPending) {
    return <p className="text-muted-foreground">Loading the map…</p>
  }
  const issues = mergeIssues(map.issues, answerIssues(draft, map.phaseKeys, answer, map.drawn))
  const open = (id: string, kind: DiagramActivation) => {
    if (kind === 'mode') {
      if (!locked) {
        apply(toggleMode(draft.phases, phaseOf(id.slice('mode:'.length))))
      }
      return
    }
    const target = mapTarget(id, kind, map.phaseKeys, map.diagram)
    if (target !== null) {
      onOpen(target)
    }
  }
  // Mode chips say what they switch to, not only what the phase is.
  const labels = Object.fromEntries(
    draft.phases.map((phase, at) => [
      `mode:${map.phaseKeys[at] ?? ''}`,
      `Mode of ${phaseLabel(phase)}: ${phase.mode}. Switch to ${phase.mode === 'once' ? 'loop' : 'once'}`,
    ]),
  )
  const focus = focused?.match(/^(?:phase|mode):(.*)$/s)?.[1]
  const at = focus === undefined ? -1 : phaseOf(focus)
  const current = draft.phases[at]

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
        it; a Scene Type opens its own page. Drag a phase to reorder it, or press its mode to switch
        it.
      </p>
      <div className="overflow-x-auto">
        <FlowDiagramView
          diagram={map.diagram}
          issues={issues}
          selectedId={selectedMapId(draft, map.phaseKeys, node)}
          onActivate={open}
          onFocusNode={setFocused}
          labels={labels}
          drag={drag}
        />
      </div>
      <MapActionBar title={current === undefined ? null : `Phase ${phaseLabel(current)}`}>
        {current !== undefined && (
          <>
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={locked || at === 0}
              onClick={() => {
                apply(movePhase(draft.phases, at, at - 1))
              }}
            >
              Move earlier
            </Button>
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={locked || at === draft.phases.length - 1}
              onClick={() => {
                apply(movePhase(draft.phases, at, at + 1))
              }}
            >
              Move later
            </Button>
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={locked}
              onClick={() => {
                apply(toggleMode(draft.phases, at))
              }}
            >
              {current.mode === 'once' ? 'Switch to loop' : 'Switch to once'}
            </Button>
          </>
        )}
      </MapActionBar>
      <p role="status" className="min-h-5 text-sm">
        {said}
      </p>
    </div>
  )
}
