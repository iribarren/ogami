import { phaseHooks, type FlowDraft, type PhaseHook } from './flowDraft'
import type { FlowAnswer } from './flowError'

/** One place of the flow editor's outline: what the content panel edits. */
export type FlowNode =
  | { kind: 'settings' }
  | { kind: 'phase'; phase: number }
  | { kind: 'selection'; phase: number }
  | { kind: 'hook'; phase: number; hook: PhaseHook }
  | { kind: 'step'; phase: number; hook: PhaseHook; step: number }

export const settingsNode: FlowNode = { kind: 'settings' }

export const hookLabels: Record<PhaseHook, string> = {
  sessionOpening: 'Session opening',
  sessionClosing: 'Session closing',
  phaseOpening: 'Phase opening',
  phaseClosing: 'Phase closing',
  sceneOpening: 'Scene opening',
  sceneClosing: 'Scene closing',
  worldTurn: 'World turn',
}

/** A position as the path writes it: whole, without leading zeros. */
const position = /^(?:0|[1-9]\d{0,3})$/

/**
 * The node a path names (`settings`, `phases.2`, `phases.2.selection`, `phases.2.hooks.phaseClosing`,
 * `phases.2.hooks.phaseClosing.0`), or null when it is anything else. It reads the syntax only;
 * `resolveNode` checks it against a draft.
 */
export function parseNodePath(path: unknown): FlowNode | null {
  if (typeof path !== 'string') {
    return null
  }
  const parts = path.split('.')
  if (parts.length === 1) {
    return parts[0] === 'settings' ? settingsNode : null
  }
  const [root, at, part, name, last] = parts
  if (root !== 'phases' || at === undefined || !position.test(at)) {
    return null
  }
  const phase = Number(at)
  if (parts.length === 2) {
    return { kind: 'phase', phase }
  }
  if (part === 'selection') {
    return parts.length === 3 ? { kind: 'selection', phase } : null
  }
  const hook = phaseHooks.find((candidate) => candidate === name)
  if (part !== 'hooks' || hook === undefined || parts.length > 5) {
    return null
  }
  if (last === undefined) {
    return parts.length === 4 ? { kind: 'hook', phase, hook } : null
  }
  return position.test(last) ? { kind: 'step', phase, hook, step: Number(last) } : null
}

/** The `node` search param: the path when it is one, else undefined (never the raw value). */
export function nodeParam(value: unknown): string | undefined {
  return typeof value === 'string' && parseNodePath(value) !== null ? value : undefined
}

export function nodePath(node: FlowNode): string {
  switch (node.kind) {
    case 'settings':
      return 'settings'
    case 'phase':
      return `phases.${String(node.phase)}`
    case 'selection':
      return `phases.${String(node.phase)}.selection`
    case 'hook':
      return `phases.${String(node.phase)}.hooks.${node.hook}`
    case 'step':
      return `phases.${String(node.phase)}.hooks.${node.hook}.${String(node.step)}`
  }
}

/**
 * The node a path names in `draft`. A path that names nothing opens the flow settings; one whose
 * phase exists but whose step does not opens that hook, so a deleted or moved node never leaves
 * the content empty.
 */
export function resolveNode(draft: FlowDraft, path: string | undefined): FlowNode {
  const node = parseNodePath(path)
  if (node === null || node.kind === 'settings') {
    return settingsNode
  }
  const phase = draft.phases[node.phase]
  if (phase === undefined) {
    return settingsNode
  }
  if (node.kind === 'step' && node.step >= (phase.hooks[node.hook] ?? []).length) {
    return { kind: 'hook', phase: node.phase, hook: node.hook }
  }
  return node
}

/** The phase as the outline names it: "2. Exploration", or its key when it has no name. */
export function phaseLabel(draft: FlowDraft, index: number): string {
  const phase = draft.phases[index]
  const name = phase === undefined || phase.name.trim() === '' ? phase?.key : phase.name
  return `${String(index + 1)}. ${name ?? ''}`
}

/** The breadcrumb of a node, outermost first. */
export function nodeTrail(draft: FlowDraft, node: FlowNode): string[] {
  switch (node.kind) {
    case 'settings':
      return ['Flow settings']
    case 'phase':
      return [phaseLabel(draft, node.phase)]
    case 'selection':
      return [phaseLabel(draft, node.phase), 'Selection']
    case 'hook':
      return [phaseLabel(draft, node.phase), hookLabels[node.hook]]
    case 'step':
      return [phaseLabel(draft, node.phase), hookLabels[node.hook], `Step ${String(node.step + 1)}`]
  }
}

/**
 * The first node an answer is about: a flow field, then the phases in order, each with its fields
 * before its selection and its hooks. Null when it says nothing about a node (a message for the
 * page, or a clean answer).
 */
export function answerNode(answer: FlowAnswer): FlowNode | null {
  if (Object.keys(answer.fields).length > 0) {
    return settingsNode
  }
  for (let phase = 0; phase < answer.phases.length; phase += 1) {
    const said = answer.phases[phase]
    if (said === undefined) {
      continue
    }
    if (Object.keys(said.fields).length > 0 || said.messages.length > 0) {
      return { kind: 'phase', phase }
    }
    if (said.selection.length > 0) {
      return { kind: 'selection', phase }
    }
    for (const hook of phaseHooks) {
      const errors = said.hooks[hook] ?? []
      const first = errors[0]
      if (first !== undefined) {
        // The first error decides: one on the list itself selects the hook, not a later step.
        return first.index === null
          ? { kind: 'hook', phase, hook }
          : { kind: 'step', phase, hook, step: first.index }
      }
    }
  }
  return null
}
