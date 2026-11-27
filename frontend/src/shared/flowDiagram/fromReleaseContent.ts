import {
  type DiagramHook,
  type DiagramPhase,
  type DiagramSceneType,
  type DiagramSelection,
  type DiagramTable,
  type DiagramTransition,
  FlowDiagramError,
  type FlowDiagram,
  PHASE_HOOKS,
  type TransitionKind,
  type TransitionSource,
} from './flowDiagram'
import { HOOK_LABELS } from './hookLabels'

/**
 * Builds a FlowDiagram from GameSystem release content (schema version 2 JSON, which the preset files
 * share). It reads only what a diagram draws and walks the same places the backend does to find
 * effects (StepParts, FlowReferences). Anything the contract does not allow throws a FlowDiagramError
 * naming where it is: a malformed release must fail loudly, never draw a wrong diagram.
 */

type Json = Record<string, unknown>

/** A named thing of the release (a Scene Type or an oracle table) and where it sits in its list. */
interface Entry {
  key: string
  name: string
  body: Json
  path: string
}

interface Catalog {
  sceneTypes: Map<string, Entry>
  tables: Map<string, Entry>
}

/** The step lists of a Scene Type, in the order the player meets them. */
const SCENE_TYPE_PARTS = ['setup', 'play', 'closing'] as const

function isTransitionKind(kind: unknown): kind is TransitionKind {
  return kind === 'nextScene' || kind === 'switchSceneType' || kind === 'endPhase'
}

export function flowDiagramFromReleaseContent(content: unknown, flowKey: string): FlowDiagram {
  const root = readObject(content, 'content')
  const catalog: Catalog = {
    sceneTypes: readCatalog(root.sceneTypes, 'sceneTypes'),
    tables: readCatalog(readOptionalObject(root.oracles, 'oracles').tables, 'oracles.tables'),
  }

  const flows = readList(root.flows, 'flows')
  const flow = flows
    .map((item, index) => readObject(item, `flows[${String(index)}]`))
    .find((item) => item.key === flowKey)
  if (flow === undefined) {
    throw new FlowDiagramError('flows', `no flow "${flowKey}"`)
  }

  const flowPath = `flows[${flowKey}]`
  const phaseBodies = readList(flow.phases, `${flowPath}.phases`)
  const transitions: DiagramTransition[] = []
  const phases = phaseBodies.map((item, index) => {
    const body = readObject(item, `${flowPath}.phases[${String(index)}]`)
    const walked = walkPhase(
      catalog,
      body,
      `${flowPath}.phases[${String(index)}]`,
      readNextPhase(phaseBodies, index, flowPath),
    )
    transitions.push(...walked.transitions)

    return walked.phase
  })

  return {
    key: flowKey,
    name: readText(flow.name, `${flowPath}.name`),
    phases,
    transitions,
  }
}

function readNextPhase(phases: unknown[], index: number, flowPath: string): NextPhase | null {
  if (index + 1 >= phases.length) {
    return null
  }
  const path = `${flowPath}.phases[${String(index + 1)}]`
  const next = readObject(phases[index + 1], path)

  return { key: readText(next.key, `${path}.key`), name: readText(next.name, `${path}.name`) }
}

interface PhaseWalk {
  phase: DiagramPhase
  transitions: DiagramTransition[]
}

interface NextPhase {
  key: string
  name: string
}

/** One unit of the walk: where its effects are drawn from, and the step lists to search. */
interface Source {
  from: TransitionSource
  lists: { path: string; steps: unknown }[]
}

function walkPhase(
  catalog: Catalog,
  body: Json,
  path: string,
  nextPhase: NextPhase | null,
): PhaseWalk {
  const key = readText(body.key, `${path}.key`)
  const name = readText(body.name, `${path}.name`)
  const mode = body.mode
  if (mode !== 'once' && mode !== 'loop') {
    throw new FlowDiagramError(
      `${path}.mode`,
      `must be "once" or "loop", got ${JSON.stringify(mode)}`,
    )
  }
  const act = body.act === undefined || body.act === null ? null : readText(body.act, `${path}.act`)

  const selection = readSelection(
    catalog,
    readObject(body.selection, `${path}.selection`),
    `${path}.selection`,
  )
  const selected = new Set(selection.sceneTypes.map((sceneType) => sceneType.key))
  const reached: DiagramSceneType[] = []
  const transitions = new Map<string, DiagramTransition>()
  const queue: Source[] = []

  const reach = (sceneType: Entry): void => {
    if (selected.has(sceneType.key)) {
      return
    }
    selected.add(sceneType.key)
    reached.push(toSceneType(sceneType))
    queue.push(sceneTypeSource(sceneType))
  }

  const emit = (
    effect: Json,
    effectPath: string,
    from: TransitionSource,
    via: DiagramTable | null,
  ): void => {
    const kind = effect.kind
    if (!isTransitionKind(kind)) {
      return
    }
    let to: DiagramTransition['to']
    if (kind === 'endPhase') {
      to =
        nextPhase === null
          ? { type: 'end' }
          : { type: 'nextPhase', phase: nextPhase.key, name: nextPhase.name }
    } else {
      const sceneType = readKnown(
        catalog.sceneTypes,
        effect.sceneType,
        `${effectPath}.sceneType`,
        'Scene Type',
      )
      to = { type: 'sceneType', sceneType: sceneType.key }
      reach(sceneType)
    }
    const transition: DiagramTransition = { kind, phase: key, from, to, via }
    transitions.set(JSON.stringify(transition), transition)
  }

  /**
   * The effects on the entries of a table, and of the tables its entries roll in turn. The entries
   * of a selection table are drawn from the table node itself, so they carry no "via".
   */
  const rollTable = (table: Entry, from: TransitionSource, seen: Set<string>): void => {
    if (seen.has(table.key)) {
      return
    }
    seen.add(table.key)
    readList(table.body.entries, `${table.path}.entries`).forEach((item, index) => {
      const entryPath = `${table.path}.entries[${String(index)}]`
      const entry = readObject(item, entryPath)
      readList(entry.effects, `${entryPath}.effects`).forEach((effect, effectIndex) => {
        const effectPath = `${entryPath}.effects[${String(effectIndex)}]`
        const via = from.type === 'selectionTable' ? null : toTable(table)
        emit(readObject(effect, effectPath), effectPath, from, via)
      })
      if (typeof entry.table === 'string') {
        rollTable(readKnown(catalog.tables, entry.table, `${entryPath}.table`, 'table'), from, seen)
      }
    })
  }

  const walkSteps = (source: Source): void => {
    for (const { path: listPath, steps } of source.lists) {
      readList(steps, listPath).forEach((item, index) => {
        const stepPath = `${listPath}[${String(index)}]`
        const step = readObject(item, stepPath)
        for (const [outcomePath, outcome] of outcomes(step, stepPath)) {
          readList(outcome.effects, `${outcomePath}.effects`).forEach((effect, effectIndex) => {
            const effectPath = `${outcomePath}.effects[${String(effectIndex)}]`
            emit(readObject(effect, effectPath), effectPath, source.from, null)
          })
        }
        for (const [tablePath, tableKey] of rolledTables(step, stepPath)) {
          rollTable(readKnown(catalog.tables, tableKey, tablePath, 'table'), source.from, new Set())
        }
      })
    }
  }

  if (selection.rule === 'oracle') {
    const table = readKnown(catalog.tables, selection.table.key, `${path}.selection.table`, 'table')
    rollTable(table, { type: 'selectionTable' }, new Set())
  }

  for (const sceneType of selection.sceneTypes) {
    queue.push(
      sceneTypeSource(
        readKnown(catalog.sceneTypes, sceneType.key, `${path}.selection`, 'Scene Type'),
      ),
    )
  }
  const hooks: DiagramHook[] = []
  for (const hook of PHASE_HOOKS) {
    const steps = readList(body[hook], `${path}.${hook}`)
    if (steps.length > 0) {
      hooks.push({ hook, label: HOOK_LABELS[hook], steps: steps.length })
      queue.push({ from: { type: 'hook', hook }, lists: [{ path: `${path}.${hook}`, steps }] })
    }
  }

  // The queue grows while it is walked: a Scene Type a transition reaches is walked in turn.
  for (const source of queue) {
    walkSteps(source)
  }

  return {
    phase: { key, name, act, mode, selection, reached, hooks },
    transitions: [...transitions.values()],
  }
}

function sceneTypeSource(entry: Entry): Source {
  return {
    from: { type: 'sceneType', sceneType: entry.key },
    lists: SCENE_TYPE_PARTS.map((part) => ({
      path: `${entry.path}.${part}`,
      steps: entry.body[part],
    })),
  }
}

function readSelection(catalog: Catalog, selection: Json, path: string): DiagramSelection {
  const rule = selection.rule
  if (rule === 'sequence' || rule === 'player') {
    const sceneTypes = readList(selection.sceneTypes, `${path}.sceneTypes`).map((key, index) =>
      toSceneType(
        readKnown(catalog.sceneTypes, key, `${path}.sceneTypes[${String(index)}]`, 'Scene Type'),
      ),
    )

    return { rule, sceneTypes }
  }
  if (rule === 'oracle') {
    const table = readKnown(catalog.tables, selection.table, `${path}.table`, 'table')
    const sceneTypes = new Map<string, DiagramSceneType>()
    readList(table.body.entries, `${table.path}.entries`).forEach((item, index) => {
      const entryPath = `${table.path}.entries[${String(index)}]`
      const sceneType = readKnown(
        catalog.sceneTypes,
        readObject(item, entryPath).sceneType,
        `${entryPath}.sceneType`,
        'Scene Type',
      )
      sceneTypes.set(sceneType.key, toSceneType(sceneType))
    })

    return { rule, table: toTable(table), sceneTypes: [...sceneTypes.values()] }
  }
  throw new FlowDiagramError(
    `${path}.rule`,
    `must be "sequence", "player" or "oracle", got ${JSON.stringify(rule)}`,
  )
}

/** The parts of a step that may carry effects (StepParts::outcomes): the step, bands, options, outcomes, branches and otherwise. */
function outcomes(step: Json, path: string): [string, Json][] {
  const found: [string, Json][] = [[path, step]]
  for (const name of ['bands', 'options', 'outcomes']) {
    readList(step[name], `${path}.${name}`).forEach((item, index) => {
      if (isObject(item)) {
        found.push([`${path}.${name}[${String(index)}]`, item])
      }
    })
  }
  if (isObject(step.branches)) {
    for (const [name, item] of Object.entries(step.branches)) {
      if (isObject(item)) {
        found.push([`${path}.branches.${name}`, item])
      }
    }
  } else {
    readList(step.branches, `${path}.branches`).forEach((item, index) => {
      if (isObject(item)) {
        found.push([`${path}.branches[${String(index)}]`, item])
      }
    })
  }
  if (isObject(step.otherwise)) {
    found.push([`${path}.otherwise`, step.otherwise])
  }

  return found
}

/** The table keys a step rolls (StepParts::rolledTables): a "table" step's default and its tables by Scene Type. */
function rolledTables(step: Json, path: string): [string, string][] {
  if (step.kind !== 'table') {
    return []
  }
  const found: [string, string][] = []
  if (step.table !== undefined) {
    found.push([`${path}.table`, readText(step.table, `${path}.table`)])
  }
  if (isObject(step.tableBySceneType)) {
    for (const [sceneType, table] of Object.entries(step.tableBySceneType)) {
      found.push([
        `${path}.tableBySceneType.${sceneType}`,
        readText(table, `${path}.tableBySceneType.${sceneType}`),
      ])
    }
  }

  return found
}

function toSceneType(entry: Entry): DiagramSceneType {
  return { key: entry.key, name: entry.name }
}

function toTable(entry: Entry): DiagramTable {
  return { key: entry.key, name: entry.name }
}

/** The named items of a list, by key. A missing list is empty; an item without key or name throws. */
function readCatalog(value: unknown, path: string): Map<string, Entry> {
  const catalog = new Map<string, Entry>()
  readList(value, path).forEach((item, index) => {
    const itemPath = `${path}[${String(index)}]`
    const body = readObject(item, itemPath)
    const key = readText(body.key, `${itemPath}.key`)
    if (!catalog.has(key)) {
      catalog.set(key, { key, name: readText(body.name, `${itemPath}.name`), body, path: itemPath })
    }
  })

  return catalog
}

function readKnown(catalog: Map<string, Entry>, key: unknown, path: string, what: string): Entry {
  const entry = typeof key === 'string' ? catalog.get(key) : undefined
  if (entry === undefined) {
    throw new FlowDiagramError(path, `${what} ${JSON.stringify(key)} is not in the release`)
  }

  return entry
}

function isObject(value: unknown): value is Json {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function readObject(value: unknown, path: string): Json {
  if (!isObject(value)) {
    throw new FlowDiagramError(path, 'must be an object')
  }

  return value
}

function readOptionalObject(value: unknown, path: string): Json {
  return value === undefined ? {} : readObject(value, path)
}

/** A list; an absent one is empty. */
function readList(value: unknown, path: string): unknown[] {
  if (value === undefined) {
    return []
  }
  if (!Array.isArray(value)) {
    throw new FlowDiagramError(path, 'must be a list')
  }

  return value
}

function readText(value: unknown, path: string): string {
  if (typeof value !== 'string' || value === '') {
    throw new FlowDiagramError(path, 'must be a non-empty string')
  }

  return value
}
