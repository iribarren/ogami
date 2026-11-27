import type { DiagramActivation, DiagramIssues, FlowDiagram } from '@/shared/flowDiagram'

import { phaseHooks, type FlowDraft } from './flowDraft'
import type { FlowAnswer } from './flowError'
import type { FlowNode } from './flowNode'

/** What activating a node of the Map opens: a panel of the editor, or a Scene Type's own page. */
export type MapTarget = { type: 'node'; node: FlowNode } | { type: 'sceneType'; sceneType: string }

/**
 * What the node `id` of the Map is, given the key each phase has on it (`mapPhaseKeys`). Null when
 * the id names nothing the draft has. Ids are `phase:<key>`, `choice:<key>`, `table:<key>`,
 * `hook:<key>:<hook>` and `scene:<key>:<sceneType>`; a phase key is matched whole, never split on
 * its colons, since an author may type any text into it.
 */
export function mapTarget(
  id: string,
  kind: DiagramActivation,
  phaseKeys: readonly string[],
  diagram: FlowDiagram,
): MapTarget | null {
  const phaseAt = (key: string) => phaseKeys.indexOf(key)
  switch (kind) {
    case 'phase': {
      const phase = phaseAt(id.slice('phase:'.length))
      return phase < 0 ? null : { type: 'node', node: { kind: 'phase', phase } }
    }
    case 'choice':
    case 'table': {
      const phase = phaseAt(id.slice(`${kind}:`.length))
      return phase < 0 ? null : { type: 'node', node: { kind: 'selection', phase } }
    }
    case 'hook': {
      const colon = id.lastIndexOf(':')
      const hook = phaseHooks.find((name) => name === id.slice(colon + 1))
      const phase = phaseAt(id.slice('hook:'.length, colon))
      return hook === undefined || phase < 0
        ? null
        : { type: 'node', node: { kind: 'hook', phase, hook } }
    }
    case 'sceneType': {
      const scene = sceneOf(id, phaseKeys, diagram)
      return scene === null ? null : { type: 'sceneType', sceneType: scene.sceneType }
    }
  }
}

/**
 * The phase and Scene Type a `scene:<phase>:<sceneType>` id stands for. Keys may hold colons, so the
 * id is never split: a phase owns it when the id starts with its key and the drawing has that Scene
 * Type in it. Null when no phase does, or when two do (the id is then ambiguous).
 */
export function sceneOf(
  id: string,
  phaseKeys: readonly string[],
  diagram: FlowDiagram,
): { phase: number; sceneType: string } | null {
  const owners = phaseKeys.flatMap((key, phase) => {
    const prefix = `scene:${key}:`
    const drawn = diagram.phases.find((candidate) => candidate.key === key)
    const sceneType = id.slice(prefix.length)
    const holds =
      id.startsWith(prefix) &&
      [...(drawn?.selection.sceneTypes ?? []), ...(drawn?.reached ?? [])].some(
        (candidate) => candidate.key === sceneType,
      )
    return holds ? [{ phase, sceneType }] : []
  })
  return owners.length === 1 ? (owners[0] ?? null) : null
}

/** The id of the diagram node a selected editor node stands for; undefined when it has none (the flow settings, a sequence's selection). */
export function selectedMapId(
  draft: FlowDraft,
  phaseKeys: readonly string[],
  node: FlowNode,
): string | undefined {
  if (node.kind === 'settings') {
    return undefined
  }
  const key = phaseKeys[node.phase]
  const phase = draft.phases[node.phase]
  if (key === undefined || phase === undefined) {
    return undefined
  }
  switch (node.kind) {
    case 'phase':
      return `phase:${key}`
    case 'selection':
      return phase.selection.rule === 'player'
        ? `choice:${key}`
        : phase.selection.rule === 'oracle'
          ? `table:${key}`
          : undefined
    case 'hook':
    case 'step':
      return `hook:${key}:${node.hook}`
  }
}

/**
 * The nodes a message about the phase's selection sits on: what draws it, or the phase box when
 * nothing does (a sequence whose Scene Types are all missing). A Scene Type listed twice has one node.
 */
function selectionNodes(
  draft: FlowDraft,
  phase: number,
  key: string,
  drawn: ReadonlySet<string> | undefined,
): string[] {
  const selection = draft.phases[phase]?.selection
  if (selection?.rule === 'player') {
    return [`choice:${key}`]
  }
  if (selection?.rule === 'oracle') {
    return [`table:${key}`]
  }
  const nodes = new Set(
    (selection?.sceneTypes ?? [])
      .map((sceneType) => `scene:${key}:${sceneType}`)
      .filter((node) => drawn === undefined || drawn.has(node)),
  )
  return nodes.size === 0 ? [`phase:${key}`] : [...nodes]
}

/**
 * Where a refused save or a failed check is shown on the Map: a phase's own fields and messages on
 * its box, its selection on the nodes that draw it, and a hook's step errors on its chip. What is
 * about the flow itself, or about no phase of the draft, has no node and is left to the page.
 */
export function answerIssues(
  draft: FlowDraft,
  phaseKeys: readonly string[],
  answer: FlowAnswer,
  /** The ids the drawing has (`FlowMapDiagram.drawn`); without it every Scene Type is taken as drawn. */
  drawn?: ReadonlySet<string>,
): DiagramIssues {
  const issues: DiagramIssues = {}
  const add = (node: string, messages: string[]) => {
    if (messages.length > 0) {
      issues[node] = [...(issues[node] ?? []), ...messages]
    }
  }
  answer.phases.forEach((said, phase) => {
    const key = phaseKeys[phase]
    if (said === undefined || key === undefined) {
      return
    }
    add(`phase:${key}`, [...Object.values(said.fields), ...said.messages])
    for (const node of selectionNodes(draft, phase, key, drawn)) {
      add(node, said.selection)
    }
    for (const hook of phaseHooks) {
      add(
        `hook:${key}:${hook}`,
        (said.hooks[hook] ?? []).map(({ message }) => message),
      )
    }
  })
  return issues
}
