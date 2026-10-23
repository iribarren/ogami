import { useMemo } from 'react'

import type { GameSystemTab } from './gameSystemTabs'
import { phaseHooks } from './narrative/flows/flowDraft'
import { hookLabels, nodePath } from './narrative/flows/flowNode'
import { useGameSystemNarrative, type NarrativeItem } from './narrative/useGameSystemNarrative'
import { useGameSystemOracles } from './oracles/useGameSystemOracles'

/** An item of the draft as the editors read it: its key and its definition as in a release. */
export interface NamedItem {
  key: string
  definition: Record<string, unknown>
}

/** The draft's items by section, in stored order: a path says `flows[1]`, this says which flow. */
export interface LocatorNames {
  flows: NamedItem[]
  sceneTypes: NamedItem[]
  tables: NamedItem[]
  likelihood: NamedItem[]
  trackers: NamedItem[]
  tags: NamedItem[]
  factSlots: NamedItem[]
}

export const noNames: LocatorNames = {
  flows: [],
  sceneTypes: [],
  tables: [],
  likelihood: [],
  trackers: [],
  tags: [],
  factSlots: [],
}

/** Where "Go to field" leads. */
export type Target =
  | { kind: 'tab'; tab: GameSystemTab }
  | { kind: 'flow'; flow: string; node?: string }
  | { kind: 'table'; table: string }
  | { kind: 'likelihood'; oracle: string }
  | { kind: 'sceneType'; sceneType: string }

export interface ErrorLocation {
  /** The panel the error belongs to: "Flows · One-shot", or the section alone. */
  group: string
  /** The human path, outermost first; empty when the path is not one this knows. */
  trail: string[]
  target: Target
}

type Token = { name: string } | { index: number }

/** `flows[0].phases[1].name` as `flows`, 0, `phases`, 1, `name`. */
function tokenize(path: string): Token[] {
  return [...path.matchAll(/([A-Za-z_][\w-]*)|\[(\d+)\]/g)].map((match) =>
    match[1] === undefined ? { index: Number(match[2]) } : { name: match[1] },
  )
}

const asRecord = (value: unknown): Record<string, unknown> =>
  typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : {}

const asList = (value: unknown): unknown[] => (Array.isArray(value) ? (value as unknown[]) : [])

/** `skipLabel` as "Skip label". */
function humanize(name: string): string {
  const words = name.replace(/([a-z0-9])([A-Z])/g, '$1 $2').toLowerCase()
  return words.charAt(0).toUpperCase() + words.slice(1)
}

/** The rest of a path as words: a name is humanized, a position after it is counted from 1. */
function words(tokens: Token[]): string[] {
  const trail: string[] = []
  for (const token of tokens) {
    if ('name' in token) {
      trail.push(humanize(token.name))
    } else {
      const last = trail.pop()
      if (last !== undefined) {
        trail.push(`${last} ${String(token.index + 1)}`)
      }
    }
  }
  return trail
}

/** "Step 2 “Did you learn…”": the step's title or prompt, shortened, when the draft has it. */
function stepLabel(step: unknown, index: number): string {
  const record = asRecord(step)
  const text = [record.title, record.prompt].find((value) => typeof value === 'string')
  const shown =
    typeof text === 'string' ? (text.length > 24 ? `${text.slice(0, 24).trimEnd()}…` : text) : ''
  return `Step ${String(index + 1)}${shown === '' ? '' : ` “${shown}”`}`
}

/** A list of steps at the start of `tokens`: "Setup", then the step and the words that follow. */
function stepTrail(label: string, list: unknown, tokens: Token[]): string[] {
  const [first, ...rest] = tokens
  if (first === undefined || !('index' in first)) {
    return [label, ...words(tokens)]
  }
  return [label, stepLabel(asList(list)[first.index], first.index), ...words(rest)]
}

const sections = {
  flows: { label: 'Flows', names: 'flows' },
  sceneTypes: { label: 'Scene Types', names: 'sceneTypes' },
  trackers: { label: 'Trackers', names: 'trackers' },
  tags: { label: 'Tags', names: 'tags' },
  factSlots: { label: 'Fact slots', names: 'factSlots' },
} as const

const overview: ErrorLocation = {
  group: 'Draft',
  trail: [],
  target: { kind: 'tab', tab: 'overview' },
}

/** The first names of a release path, as `locateError` reads them. */
const pathHeads = new Set([
  'flows',
  'sceneTypes',
  'trackers',
  'tags',
  'factSlots',
  'oracles',
  'sheet',
  'checks',
  'library',
  'gameSystem',
  'theme',
])

/** Whether `text` is wholly a release path: a known head, then `.names` and `[n]` positions. */
function isReleasePath(text: string): boolean {
  const [head] = tokenize(text)
  return (
    head !== undefined &&
    'name' in head &&
    pathHeads.has(head.name) &&
    /^[A-Za-z_][\w-]*(\.[A-Za-z_][\w-]*|\[\d+\])*$/.test(text)
  )
}

/**
 * A warning is `"path: reason"`; one without a path is only its reason. Splits at the first `': '`,
 * and only when what comes before it is a release path, so a colon in a sentence stays in it.
 */
export function splitWarning(warning: string): { path?: string; reason: string } {
  const at = warning.indexOf(': ')
  if (at === -1 || !isReleasePath(warning.slice(0, at))) {
    return { reason: warning }
  }
  return { path: warning.slice(0, at), reason: warning.slice(at + 2) }
}

/** Where in the draft a validation path is, in words, and where to edit it. Never throws. */
export function locateError(path: string, names: LocatorNames): ErrorLocation {
  const tokens = tokenize(path)
  const [head, second, ...rest] = tokens
  if (head === undefined || !('name' in head)) {
    return overview
  }
  const tab = (
    section: GameSystemTab,
    group: string,
    trail: Token[] = tokens.slice(1),
  ): ErrorLocation => ({
    group,
    trail: words(trail),
    target: { kind: 'tab', tab: section },
  })

  switch (head.name) {
    case 'flows':
    case 'sceneTypes':
    case 'trackers':
    case 'tags':
    case 'factSlots': {
      const { label, names: list } = sections[head.name]
      const at = second !== undefined && 'index' in second ? second.index : undefined
      const found = at === undefined ? undefined : names[list][at]
      const group = at === undefined ? label : `${label} · ${itemName(found, at)}`
      const section = head.name === 'flows' || head.name === 'sceneTypes' ? head.name : 'narrative'
      if (found === undefined || at === undefined) {
        return tab(section, group)
      }
      if (head.name === 'flows') {
        return flowLocation(group, found, rest)
      }
      if (head.name === 'sceneTypes') {
        const [part, ...after] = rest
        const label = part !== undefined && 'name' in part ? humanize(part.name) : ''
        return {
          group,
          trail:
            part !== undefined && 'name' in part
              ? stepTrail(label, found.definition[part.name], after)
              : words(rest),
          target: { kind: 'sceneType', sceneType: found.key },
        }
      }
      return tab(section, group, rest)
    }
    case 'oracles': {
      const kind = second !== undefined && 'name' in second ? second.name : ''
      const [index, ...after] = rest
      const at = index !== undefined && 'index' in index ? index.index : undefined
      const found =
        at === undefined ? undefined : names[kind === 'likelihood' ? 'likelihood' : 'tables'][at]
      if ((kind !== 'tables' && kind !== 'likelihood') || at === undefined || found === undefined) {
        return tab('oracles', 'Oracles', tokens.slice(1))
      }
      return {
        group: `${kind === 'tables' ? 'Oracle tables' : 'Likelihood oracles'} · ${itemName(found, at)}`,
        trail: words(after),
        target:
          kind === 'tables'
            ? { kind: 'table', table: found.key }
            : { kind: 'likelihood', oracle: found.key },
      }
    }
    case 'sheet':
      return tab('sheet', 'Sheet')
    case 'checks':
      return tab('checks', 'Checks')
    case 'library':
      return tab('library', 'Library')
    case 'gameSystem':
      return { ...overview, trail: words(tokens.slice(1)) }
    case 'theme':
      return { ...overview, trail: ['Theme'] }
    default:
      return overview
  }
}

function itemName(item: NamedItem | undefined, index: number): string {
  const name = item?.definition.name
  return typeof name === 'string' && name !== '' ? name : (item?.key ?? String(index + 1))
}

/** A flow path below `flows[n]`: the phases, hooks and steps with the outline node they are on. */
function flowLocation(group: string, flow: NamedItem, rest: Token[]): ErrorLocation {
  const target: Target = { kind: 'flow', flow: flow.key }
  const [phasesToken, phaseToken, ...after] = rest
  const phaseAt = phaseToken !== undefined && 'index' in phaseToken ? phaseToken.index : undefined
  if (
    phasesToken === undefined ||
    !('name' in phasesToken) ||
    phasesToken.name !== 'phases' ||
    phaseAt === undefined
  ) {
    return { group, trail: words(rest), target }
  }
  const phase = asRecord(asList(flow.definition.phases)[phaseAt])
  const name = typeof phase.name === 'string' && phase.name !== '' ? phase.name : phase.key
  const phaseLabel = `${String(phaseAt + 1)}. ${typeof name === 'string' ? name : ''}`.trimEnd()
  const [part, ...inner] = after
  const partName = part !== undefined && 'name' in part ? part.name : undefined
  const hook = phaseHooks.find((candidate) => candidate === partName)
  if (hook !== undefined) {
    const [stepToken] = inner
    const step = stepToken !== undefined && 'index' in stepToken ? stepToken.index : undefined
    return {
      group,
      trail: [phaseLabel, ...stepTrail(hookLabels[hook], phase[hook], inner)],
      target: {
        ...target,
        node: nodePath(
          step === undefined
            ? { kind: 'hook', phase: phaseAt, hook }
            : { kind: 'step', phase: phaseAt, hook, step },
        ),
      },
    }
  }
  const node = nodePath(
    partName === 'selection'
      ? { kind: 'selection', phase: phaseAt }
      : { kind: 'phase', phase: phaseAt },
  )
  return { group, trail: [phaseLabel, ...words(after)], target: { ...target, node } }
}

/** The draft's item names, from the narrative and oracle reads; empty until they arrive (or when they fail). */
export function useNames(gameSystemKey: string): LocatorNames {
  const narrative = useGameSystemNarrative(gameSystemKey)
  const oracles = useGameSystemOracles(gameSystemKey)
  const narrativeData = narrative.data
  const oraclesData = oracles.data
  return useMemo(() => {
    const items = (list: NarrativeItem[] | undefined): NamedItem[] => list ?? []
    const oracleItems = (list: { definition: object }[] | undefined): NamedItem[] =>
      (list ?? []).map(({ definition }) => {
        const record = definition as Record<string, unknown>
        return { key: typeof record.key === 'string' ? record.key : '', definition: record }
      })
    return {
      ...noNames,
      flows: items(narrativeData?.flows),
      sceneTypes: items(narrativeData?.sceneTypes),
      trackers: items(narrativeData?.trackers),
      tags: items(narrativeData?.tags),
      factSlots: items(narrativeData?.factSlots),
      tables: oracleItems(oraclesData?.tables),
      likelihood: oracleItems(oraclesData?.likelihood),
    }
  }, [narrativeData, oraclesData])
}
