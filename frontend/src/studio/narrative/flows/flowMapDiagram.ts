import {
  flowDiagramFromDraftContent,
  type DiagramIssues,
  type FlowDiagram,
} from '@/shared/flowDiagram'

import { setHook, flowToItem, phaseHooks, type FlowDraft } from './flowDraft'
import type { StoredItem } from '../useGameSystemNarrative'

/** What the Map draws and says: the diagram, what could not be drawn, and the key each phase has on it. */
export interface FlowMapDiagram {
  diagram: FlowDiagram
  /** By node id; the same ids as `FlowDiagramView`'s `issues`. */
  issues: DiagramIssues
  /** The key of each phase of the draft, by position, as its node ids spell it (`mapPhaseKeys`). */
  phaseKeys: string[]
}

/** Anything with the release-shaped `definition` the narrative and oracle read models carry. */
interface Defined {
  definition: unknown
}

/**
 * The key each phase has on the diagram. A phase with no key yet, or the key of an earlier phase, is
 * numbered so that node ids stay unique while the draft is being written; the save refuses both.
 */
export function mapPhaseKeys(draft: FlowDraft): string[] {
  const seen = new Set<string>()
  return draft.phases.map((phase, position) => {
    const base = phase.key.trim() === '' ? `phase-${String(position + 1)}` : phase.key.trim()
    let key = base
    for (let number = 2; seen.has(key); number += 1) {
      key = `${base}-${String(number)}`
    }
    seen.add(key)
    return key
  })
}

/** What a hook's list stands for on the map when its steps cannot be built yet: one blank step each, so the chip still counts them. */
const blankSteps = (count: number): StoredItem[] => Array.from({ length: count }, () => ({}))

/**
 * The flow draft as a diagram, for the Map. It never throws on an in-progress draft: a Scene Type or
 * table the draft does not have, and hook steps `flowToItem` cannot build yet, become issues on the
 * node they belong to; the rest of the flow is still drawn. The save's own checks stay the
 * authority. `sceneTypes` are the narrative's Scene Types and `tables` the oracle tables, both as read.
 */
export function flowDiagramFromDraft(
  draft: FlowDraft,
  sceneTypes: readonly Defined[],
  tables: readonly Defined[],
): FlowMapDiagram {
  const phaseKeys = mapPhaseKeys(draft)
  const flowKey = draft.key.trim() === '' ? 'flow' : draft.key.trim()

  // Hooks whose steps refuse a save are built blank, so that the rest of the flow still gives an item.
  const unbuilt: { phase: number; hook: (typeof phaseHooks)[number]; steps: number }[] = []
  const first = flowToItem(draft)
  let buildable = draft
  if ('errors' in first) {
    buildable = {
      ...draft,
      phases: draft.phases.map((phase, position) =>
        Object.keys(first.errors[position] ?? {}).reduce((now, hook) => {
          const name = phaseHooks.find((candidate) => candidate === hook)
          if (name === undefined) {
            return now
          }
          unbuilt.push({ phase: position, hook: name, steps: phase.hooks[name]?.length ?? 0 })
          return setHook(now, name, [])
        }, phase),
      ),
    }
  }
  const second = flowToItem(buildable)
  const item = 'item' in second ? second.item : {}
  const phases = (Array.isArray(item.phases) ? item.phases : []) as StoredItem[]

  const issues: DiagramIssues = {}
  for (const { phase, hook, steps } of unbuilt) {
    const key = phaseKeys[phase] ?? String(phase)
    const target = phases[phase]
    if (target !== undefined) {
      target[hook] = blankSteps(steps)
    }
    const errors = 'errors' in first ? first.errors[phase]?.[hook] : undefined
    const messages = Object.values(errors ?? {})
    issues[`hook:${key}:${hook}`] =
      messages.length > 0 ? messages : ['A step in this hook cannot be built yet.']
  }

  const content = {
    oracles: { tables: tables.map(({ definition }) => definition) },
    sceneTypes: sceneTypes.map(({ definition }) => definition),
    flows: [
      {
        ...item,
        key: flowKey,
        name: draft.name.trim() === '' ? flowKey : draft.name.trim(),
        phases: phases.map((phase, position) => {
          const key = phaseKeys[position] ?? String(position)
          const name = typeof phase.name === 'string' && phase.name !== '' ? phase.name : key
          return { ...phase, key, name }
        }),
      },
    ],
  }
  const built = flowDiagramFromDraftContent(content, flowKey)
  return {
    diagram: built.diagram,
    issues: mergeIssues(built.issues, issues),
    phaseKeys,
  }
}

/** The issues of every map, per node and without repeating a message. */
export function mergeIssues(...maps: DiagramIssues[]): DiagramIssues {
  const merged: DiagramIssues = {}
  for (const map of maps) {
    for (const [node, messages] of Object.entries(map)) {
      const kept = (merged[node] ??= [])
      for (const message of messages) {
        if (!kept.includes(message)) {
          kept.push(message)
        }
      }
    }
  }
  return merged
}
