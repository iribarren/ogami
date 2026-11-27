import {
  flowDiagramFromDraftContent,
  layoutFlowDiagram,
  type DiagramIssues,
  type FlowDiagram,
} from '@/shared/flowDiagram'

import { setHook, flowToItem, phaseHooks, type FlowDraft } from './flowDraft'
import type { StoredItem } from '../useGameSystemNarrative'

/** What the Map draws and says: the diagram, what could not be drawn, and the key each phase has on it. */
export interface FlowMapDiagram {
  diagram: FlowDiagram
  /** By node id; the same ids as `FlowDiagramView`'s `issues`, and every id is one the drawing has. */
  issues: DiagramIssues
  /** What is wrong with the flow as a whole, or with no node of it: the Map says it as text above the drawing. */
  flowIssues: string[]
  /** The ids of the phase boxes, nodes and header items the drawing has. */
  drawn: ReadonlySet<string>
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
  const flowIssues: string[] = []
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
  let second = flowToItem(buildable)
  if ('errors' in second) {
    // Still refused: every hook goes blank, so the phases are drawn whatever the steps say.
    const stripped = stripHooks(draft)
    buildable = stripped.draft
    unbuilt.splice(0, unbuilt.length, ...stripped.unbuilt)
    second = flowToItem(buildable)
  }
  const item = 'item' in second ? second.item : {}
  if (!('item' in second)) {
    flowIssues.push('The flow cannot be drawn from this form yet.')
  }
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
  const drawn = drawnIds(built.diagram)
  const placed: DiagramIssues = {}
  for (const [id, messages] of Object.entries(mergeIssues(built.issues, issues))) {
    if (drawn.has(id)) {
      placed[id] = messages
    } else {
      flowIssues.push(...messages.map((message) => orphanMessage(id, message)))
    }
  }
  return { diagram: built.diagram, issues: placed, flowIssues, drawn, phaseKeys }
}

/** Every hook of every phase built blank, with what each held, for a draft whose hooks could not be built one by one. */
function stripHooks(draft: FlowDraft) {
  const unbuilt: { phase: number; hook: (typeof phaseHooks)[number]; steps: number }[] = []
  const stripped = {
    ...draft,
    phases: draft.phases.map((phase, position) =>
      phaseHooks.reduce((now, hook) => {
        const steps = phase.hooks[hook]?.length ?? 0
        if (steps > 0) {
          unbuilt.push({ phase: position, hook, steps })
        }
        return setHook(now, hook, [])
      }, phase),
    ),
  }
  return { draft: stripped, unbuilt }
}

/** The ids the drawing gives its phase boxes, nodes and header items; only the phase boxes when it cannot be laid out. */
function drawnIds(diagram: FlowDiagram): Set<string> {
  try {
    const layout = layoutFlowDiagram(diagram)
    return new Set([
      ...layout.nodes.map(({ id }) => id),
      ...layout.phases.flatMap(({ id, header }) => [id, ...header.map((item) => item.id)]),
    ])
  } catch {
    return new Set(diagram.phases.map(({ key }) => `phase:${key}`))
  }
}

/** A message about something no node stands for (a list of the catalog, the flow's name), with what it is about. */
function orphanMessage(id: string, message: string): string {
  const said = message.endsWith('.') ? message : `${message}.`
  if (id === 'sceneTypes') {
    return `A Scene Type of the narrative cannot be read: ${said}`
  }
  if (id === 'oracles.tables') {
    return `An oracle table cannot be read: ${said}`
  }
  return id.startsWith('flow:') ? `The flow: ${said}` : `${id}: ${said}`
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
