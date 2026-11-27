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
  addSceneType,
  isRefusal,
  movePhase,
  phaseLabel,
  placeSceneType,
  removeSceneType,
  sceneDrop,
  toggleMode,
  type MapEdit,
  type MapResult,
  type SceneSource,
} from './flowMapEdits'
import { AddSceneType, MapActionBar } from './MapActionBar'
import {
  answerIssues,
  insertionIndex,
  mapTarget,
  selectedMapId,
  selectedScene,
  type MapTarget,
} from './flowMapNodes'
import type { FlowNode } from './flowNode'
import { ScenePalette, type PaletteScene } from './ScenePalette'

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
  // The GameSystem's Scene Types by name, for the palette, the action bar and what the Map says.
  const scenes = useMemo<PaletteScene[]>(
    () =>
      narrative.sceneTypes.map(({ key, definition }) => ({
        key,
        name: typeof definition.name === 'string' ? definition.name : key,
      })),
    [narrative.sceneTypes],
  )
  const nameOf = (key: string) => scenes.find((scene) => scene.key === key)?.name ?? key

  /** Applies the result and says it; true when the draft changed. */
  const apply = (result: MapResult): boolean => {
    if (isRefusal(result)) {
      setSaid(result.refused)
      return false
    }
    onEdit(result)
    setSaid(result.say)
    return true
  }
  /** What a drag source is when it is a Scene Type: a palette item, or a node its phase's selection lists. */
  const sceneSource = (id: string): SceneSource | null => {
    if (id.startsWith('palette:')) {
      const sceneType = id.slice('palette:'.length)
      return scenes.some(({ key }) => key === sceneType) ? { sceneType, from: null } : null
    }
    const scene = selectedScene(id, draft, map.phaseKeys, map.diagram)
    return scene === null ? null : { sceneType: scene.sceneType, from: scene.phase }
  }
  const drag = useDiagramDrag({
    layout: map.layout,
    canDrag: (id) =>
      !locked &&
      (sceneSource(id) !== null ||
        (id.startsWith('phase:') && phaseOf(id.slice('phase:'.length)) >= 0)),
    onDrop: ({ sourceId, hit, point }: DiagramDrop) => {
      const to = hit?.phase == null ? -1 : phaseOf(hit.phase)
      const source = sceneSource(sourceId)
      if (source !== null) {
        const target = draft.phases[to]
        const key = map.phaseKeys[to]
        const before =
          map.layout !== null && key !== undefined && target?.selection.rule === 'sequence'
            ? insertionIndex(map.layout, key, target.selection.sceneTypes, point)
            : null
        if (apply(sceneDrop(draft.phases, source, to, before, nameOf))) {
          followScene(source, to)
        }
        return
      }
      const from = phaseOf(sourceId.slice('phase:'.length))
      apply(
        to < 0
          ? { refused: `Dropped outside every phase: ${phaseLabel(draft.phases[from])} stays.` }
          : movePhase(draft.phases, from, to),
      )
    },
  })
  /** Moves the action bar to where an edit left a Scene Type: its new phase, or its old phase once it left every one. */
  function followScene({ sceneType, from }: SceneSource, to: number) {
    if (from === null) {
      return
    }
    const home = map.phaseKeys[to < 0 ? from : to]
    setFocused(home === undefined ? null : to < 0 ? `phase:${home}` : `scene:${home}:${sceneType}`)
  }

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
  const scene = focused === null ? null : selectedScene(focused, draft, map.phaseKeys, map.diagram)
  const sceneAt = scene?.selection.sceneTypes.indexOf(scene.sceneType) ?? -1

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
        it. Drag a Scene Type from the palette into a phase to add it, between two to place it, or
        out of every phase to remove it from the selection.
      </p>
      <div className="flex flex-col gap-3 lg:flex-row">
        <ScenePalette sceneTypes={scenes} drag={drag} locked={locked} />
        <div className="min-w-0 flex-1 overflow-x-auto">
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
      </div>
      <MapActionBar
        title={
          scene === null
            ? current === undefined
              ? null
              : `Phase ${phaseLabel(current)}`
            : `Scene Type "${nameOf(scene.sceneType)}" in phase ${phaseLabel(draft.phases[scene.phase])}`
        }
      >
        {scene !== null && (
          <>
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={locked}
              onClick={() => {
                if (apply(removeSceneType(draft.phases, scene.phase, scene.sceneType, nameOf))) {
                  setFocused(`phase:${map.phaseKeys[scene.phase] ?? ''}`)
                }
              }}
            >
              Remove from the selection
            </Button>
            {scene.selection.rule === 'sequence' && (
              <>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={locked || sceneAt <= 0}
                  onClick={() => {
                    apply(
                      placeSceneType(
                        draft.phases,
                        scene.phase,
                        scene.sceneType,
                        sceneAt - 1,
                        nameOf,
                      ),
                    )
                  }}
                >
                  Move earlier
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={locked || sceneAt === scene.selection.sceneTypes.length - 1}
                  onClick={() => {
                    apply(
                      placeSceneType(
                        draft.phases,
                        scene.phase,
                        scene.sceneType,
                        sceneAt + 2,
                        nameOf,
                      ),
                    )
                  }}
                >
                  Move later
                </Button>
              </>
            )}
          </>
        )}
        {scene === null && current !== undefined && (
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
            {current.selection.rule !== 'oracle' && (
              <AddSceneType
                // A pick belongs to the phase it was made on: another phase starts empty.
                key={focus}
                sceneTypes={scenes}
                disabled={locked}
                onAdd={(sceneType) => {
                  apply(addSceneType(draft.phases, at, sceneType, null, nameOf))
                }}
              />
            )}
          </>
        )}
      </MapActionBar>
      <p role="status" className="min-h-5 text-sm">
        {said}
      </p>
    </div>
  )
}
