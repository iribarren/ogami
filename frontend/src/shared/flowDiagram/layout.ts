import { Graph, layout, type EdgeLabel, type GraphLabel, type NodeLabel } from '@dagrejs/dagre'

import {
  type DiagramPhase,
  type DiagramTransition,
  FlowDiagramError,
  type FlowDiagram,
  type TransitionKind,
} from './flowDiagram'

/**
 * Positions for a FlowDiagram. Pure, synchronous and deterministic: the same diagram gives the same
 * numbers, and nothing is measured in a DOM (text width is estimated from the character count with
 * the constants below, which the renderer uses for its fonts).
 *
 * Phases stack top to bottom in flow order, each in a box of the same width. Dagre lays out only the
 * inside of a phase (left to right, so phases stay short and wide); this module places the phases,
 * the hook chips in the header, and routes the edges dagre cannot know about: self loops, edges from
 * a hook chip, edges to the exit port at the bottom of a phase box, and the arrows between phases.
 * A transition that cannot be placed throws a FlowDiagramError: the drawing never drops one.
 */

/** Font size (px) of node labels and phase headers. */
export const FONT_SIZE = 13
/** Estimated width (px) of one character at FONT_SIZE. */
export const CHAR_WIDTH = 7.2
/** Font size (px) of edge labels, smaller than node labels. */
export const EDGE_LABEL_FONT_SIZE = 11
/** Estimated width (px) of one character at EDGE_LABEL_FONT_SIZE. */
export const EDGE_LABEL_CHAR_WIDTH = 6.2

const NODE_HEIGHT = 32
const NODE_PAD_X = 14
const CHIP_HEIGHT = 22
const CHIP_PAD_X = 8
const CHIP_GAP = 8
const HEADER_MAX_WIDTH = 1200
const PHASE_PAD = 16
const PHASE_GAP = 44
const CONNECTOR_GAP = 36
const INNER_GAP = 12
const NODE_SEP = 32
const RANK_SEP = 64
const EDGE_LABEL_HEIGHT = 14
const LANE_GAP = 6
const GUTTER_STEP = 4
const LOOP_BASE = 14
const LOOP_STEP = 8

export interface LayoutPoint {
  x: number
  y: number
}

export interface LayoutRect {
  x: number
  y: number
  width: number
  height: number
}

export type LayoutNodeKind = 'sceneType' | 'choice' | 'table' | 'end' | 'exitStub'

export interface LayoutNode {
  /** Stable: `scene:<phase>:<sceneType>`, `choice:<phase>`, `table:<phase>`, `end` or `exit:<phase>`. */
  id: string
  kind: LayoutNodeKind
  label: string
  rect: LayoutRect
  /** The phase the node sits in; null for the end marker and the exit stub. */
  phase: string | null
  /** A Scene Type drawn only because a transition reaches it. */
  reached: boolean
}

export type LayoutHeaderItemKind = 'title' | 'mode' | 'hook'

/** What a phase box shows in its header. A hook chip carries its step count. */
export interface LayoutHeaderItem {
  /** Stable: `title:<phase>`, `mode:<phase>` or `hook:<phase>:<hook>`. */
  id: string
  kind: LayoutHeaderItemKind
  label: string
  count: number | null
  rect: LayoutRect
}

export interface LayoutPhase {
  /** Stable: `phase:<phase>`. */
  id: string
  key: string
  name: string
  rect: LayoutRect
  header: LayoutHeaderItem[]
}

/** `selection` edges join a phase's Scene Types; `phase` edges join one phase box to the next. */
export type LayoutEdgeKind = TransitionKind | 'selection' | 'phase'

export interface LayoutEdge {
  /** Stable: `transition:<index in diagram.transitions>`, `selection:<phase>:<n>` or `connector:<phase>`. */
  id: string
  kind: LayoutEdgeKind
  /** The path, first point at the source, last at the target (where the arrow head goes). */
  points: LayoutPoint[]
  /** The table a transition comes through, with where to write it. */
  label?: { text: string; x: number; y: number }
  /** Edges that come through a table are dashed. */
  dashed: boolean
}

export interface FlowDiagramLayout {
  width: number
  height: number
  phases: LayoutPhase[]
  nodes: LayoutNode[]
  edges: LayoutEdge[]
}

type Route = 'dagre' | 'self' | 'hook' | 'exit'

/** A transition with where it starts and ends resolved, and how it is routed. */
interface Resolved {
  index: number
  transition: DiagramTransition
  route: Route
  /** The node or header item the edge starts at. */
  from: string
  /** The Scene Type node the edge ends at; null for edges to the exit port. */
  to: string | null
}

interface InnerNode {
  id: string
  kind: LayoutNodeKind
  label: string
  reached: boolean
  width: number
}

interface InnerEdge {
  id: string
  kind: LayoutEdgeKind
  from: string
  to: string
  label: string | null
}

/** A phase measured on its own: header, dagre-laid inside and routed transitions, not yet placed. */
interface Measured {
  phase: DiagramPhase
  header: LayoutHeaderItem[]
  headerWidth: number
  headerHeight: number
  nodes: InnerNode[]
  inner: Map<string, LayoutRect>
  innerEdges: Map<string, LayoutPoint[]>
  innerLabels: Map<string, LayoutPoint>
  innerEdgeList: InnerEdge[]
  innerWidth: number
  innerHeight: number
  resolved: Resolved[]
}

export function layoutFlowDiagram(diagram: FlowDiagram): FlowDiagramLayout {
  diagram.transitions.forEach((transition, index) => {
    if (!diagram.phases.some((phase) => phase.key === transition.phase)) {
      throw new FlowDiagramError(
        `transitions[${String(index)}].phase`,
        `phase "${transition.phase}" is not in the diagram`,
      )
    }
  })
  const measured = diagram.phases.map((phase) => measurePhase(diagram, phase))
  const ending = endingNode(diagram)
  const width = Math.max(
    0,
    ...measured.map((item) => Math.max(item.headerWidth, item.innerWidth) + 2 * PHASE_PAD),
    ending === null ? 0 : ending.width,
  )
  const portX = width / 2

  const layoutPhases: LayoutPhase[] = []
  const nodes: LayoutNode[] = []
  const edges: LayoutEdge[] = []
  let bottom = -PHASE_GAP
  for (const item of measured) {
    const placed = placePhase(item, width, bottom + PHASE_GAP)
    layoutPhases.push(placed.phase)
    nodes.push(...placed.nodes)
    edges.push(...placed.edges)
    const next = measured[measured.indexOf(item) + 1]
    bottom = placed.phase.rect.y + placed.phase.rect.height
    if (next !== undefined) {
      edges.push(connector(item.phase.key, bottom, bottom + PHASE_GAP, portX))
    }
  }

  let height = Math.max(bottom, 0)
  const last = layoutPhases.at(-1)
  if (ending !== null && last !== undefined) {
    const rect = {
      x: portX - ending.width / 2,
      y: bottom + CONNECTOR_GAP,
      width: ending.width,
      height: NODE_HEIGHT,
    }
    nodes.push({
      id: ending.id,
      kind: ending.kind,
      label: ending.label,
      rect,
      phase: null,
      reached: false,
    })
    edges.push(connector(last.key, bottom, rect.y, portX))
    height = rect.y + rect.height
  }

  return { width, height, phases: layoutPhases, nodes, edges }
}

/** The marker after the last phase: the end of the flow, or the phase a one-phase cut leads to. */
function endingNode(
  diagram: FlowDiagram,
): { id: string; kind: LayoutNodeKind; label: string; width: number } | null {
  if (diagram.phases.length === 0) {
    return null
  }
  const outside = new Map<string, string>()
  if (diagram.continuesTo !== undefined && diagram.continuesTo !== null) {
    outside.set(diagram.continuesTo.key, diagram.continuesTo.name)
  }
  diagram.transitions.forEach((transition, index) => {
    const to = transition.to
    if (to.type !== 'nextPhase') {
      return
    }
    const known = diagram.phases.findIndex((phase) => phase.key === to.phase)
    const own = diagram.phases.findIndex((phase) => phase.key === transition.phase)
    if (known === -1) {
      outside.set(to.phase, to.name)
    } else if (known !== own + 1) {
      throw new FlowDiagramError(
        `transitions[${String(index)}].to`,
        `phase "${to.phase}" does not follow "${transition.phase}"`,
      )
    }
  })
  if (outside.size > 1) {
    throw new FlowDiagramError('transitions', 'a flow ends a phase in at most one next phase')
  }
  const [stub] = [...outside]
  if (stub === undefined) {
    return { id: 'end', kind: 'end', label: 'End of flow', width: nodeWidth('End of flow') }
  }

  return { id: `exit:${stub[0]}`, kind: 'exitStub', label: stub[1], width: nodeWidth(stub[1]) }
}

function measurePhase(diagram: FlowDiagram, phase: DiagramPhase): Measured {
  const header = layoutHeader(phase)
  const nodes = phaseNodes(phase)
  const nodeIds = new Set(nodes.map((node) => node.id))
  const headerIds = new Set(header.items.map((item) => item.id))
  const resolved: Resolved[] = []
  diagram.transitions.forEach((transition, index) => {
    if (transition.phase === phase.key) {
      resolved.push(resolveTransition(phase, transition, index, nodeIds, headerIds))
    }
  })

  const edges = selectionEdges(phase)
  for (const item of resolved) {
    if (item.route === 'dagre' && item.to !== null) {
      edges.push({
        id: `transition:${String(item.index)}`,
        kind: item.transition.kind,
        from: item.from,
        to: item.to,
        label: item.transition.via?.name ?? null,
      })
    }
  }

  const graph = new Graph<GraphLabel, NodeLabel, EdgeLabel>({ multigraph: true })
  graph.setGraph({ rankdir: 'LR', nodesep: NODE_SEP, ranksep: RANK_SEP, marginx: 0, marginy: 0 })
  graph.setDefaultEdgeLabel(() => ({}))
  for (const node of nodes) {
    graph.setNode(node.id, { width: node.width, height: NODE_HEIGHT })
  }
  for (const edge of edges) {
    const label: EdgeLabel =
      edge.label === null
        ? {}
        : { width: edgeLabelWidth(edge.label), height: EDGE_LABEL_HEIGHT, labelpos: 'c' }
    graph.setEdge(edge.from, edge.to, label, edge.id)
  }
  layout(graph)

  const inner = new Map<string, LayoutRect>()
  for (const node of nodes) {
    const box = graph.node(node.id)
    inner.set(node.id, {
      x: (box.x ?? 0) - node.width / 2,
      y: (box.y ?? 0) - NODE_HEIGHT / 2,
      width: node.width,
      height: NODE_HEIGHT,
    })
  }
  const innerEdges = new Map<string, LayoutPoint[]>()
  const innerLabels = new Map<string, LayoutPoint>()
  for (const edge of edges) {
    const routed = graph.edge(edge.from, edge.to, edge.id)
    innerEdges.set(edge.id, routed.points ?? [])
    if (edge.label !== null) {
      innerLabels.set(edge.id, { x: routed.x ?? 0, y: routed.y ?? 0 })
    }
  }

  // A graph with no node has no size; dagre reports it as -Infinity.
  const size = graph.graph()

  return {
    phase,
    header: header.items,
    headerWidth: header.width,
    headerHeight: header.height,
    nodes,
    inner,
    innerEdges,
    innerLabels,
    innerEdgeList: edges,
    innerWidth: nodes.length === 0 ? 0 : Math.max(size.width ?? 0, 0),
    innerHeight: nodes.length === 0 ? 0 : Math.max(size.height ?? 0, 0),
    resolved,
  }
}

function resolveTransition(
  phase: DiagramPhase,
  transition: DiagramTransition,
  index: number,
  nodeIds: Set<string>,
  headerIds: Set<string>,
): Resolved {
  const path = `transitions[${String(index)}]`
  const from = ((): string => {
    switch (transition.from.type) {
      case 'hook':
        return `hook:${phase.key}:${transition.from.hook}`
      case 'sceneType':
        return sceneId(phase.key, transition.from.sceneType)
      case 'selectionTable':
        return `table:${phase.key}`
    }
  })()
  if (!nodeIds.has(from) && !headerIds.has(from)) {
    throw new FlowDiagramError(`${path}.from`, `${from} is not drawn in phase "${phase.key}"`)
  }
  const isHook = transition.from.type === 'hook'
  if (transition.to.type !== 'sceneType') {
    return { index, transition, route: 'exit', from, to: null }
  }
  const to = sceneId(phase.key, transition.to.sceneType)
  if (!nodeIds.has(to)) {
    throw new FlowDiagramError(`${path}.to`, `${to} is not drawn in phase "${phase.key}"`)
  }
  const route: Route = isHook ? 'hook' : from === to ? 'self' : 'dagre'

  return { index, transition, route, from, to }
}

/** The nodes of a phase: its Scene Types and reached Scene Types, and the choice or table node. */
function phaseNodes(phase: DiagramPhase): InnerNode[] {
  const nodes: InnerNode[] = []
  const selection = phase.selection
  if (selection.rule === 'player') {
    nodes.push(innerNode(`choice:${phase.key}`, 'choice', 'Player chooses', false))
  } else if (selection.rule === 'oracle') {
    nodes.push(innerNode(`table:${phase.key}`, 'table', selection.table.name, false))
  }
  for (const sceneType of selection.sceneTypes) {
    nodes.push(innerNode(sceneId(phase.key, sceneType.key), 'sceneType', sceneType.name, false))
  }
  for (const sceneType of phase.reached) {
    nodes.push(innerNode(sceneId(phase.key, sceneType.key), 'sceneType', sceneType.name, true))
  }

  return nodes
}

/** How a selection joins its Scene Types: a chain, a fan out of a choice, or from the table node. */
function selectionEdges(phase: DiagramPhase): InnerEdge[] {
  const sceneIds = phase.selection.sceneTypes.map((sceneType) => sceneId(phase.key, sceneType.key))
  const pairs: [string, string][] = []
  switch (phase.selection.rule) {
    case 'sequence':
      sceneIds.forEach((id, index) => {
        const previous = sceneIds[index - 1]
        if (previous !== undefined) {
          pairs.push([previous, id])
        }
      })
      break
    case 'player':
      sceneIds.forEach((id) => pairs.push([`choice:${phase.key}`, id]))
      break
    case 'oracle':
      sceneIds.forEach((id) => pairs.push([`table:${phase.key}`, id]))
      break
  }

  return pairs.map(([from, to], index) => ({
    id: `selection:${phase.key}:${String(index)}`,
    kind: 'selection',
    from,
    to,
    label: null,
  }))
}

interface HeaderLayout {
  items: LayoutHeaderItem[]
  width: number
  height: number
}

/** The title with its act, the mode badge, then one chip per hook in hook order, wrapping when wide. */
function layoutHeader(phase: DiagramPhase): HeaderLayout {
  const title = phase.act === null ? phase.name : `${phase.name} · ${phase.act}`
  const items: LayoutHeaderItem[] = []
  let x = PHASE_PAD
  const put = (
    id: string,
    kind: LayoutHeaderItemKind,
    label: string,
    count: number | null,
    width: number,
    y: number,
  ): void => {
    items.push({ id, kind, label, count, rect: { x, y, width, height: CHIP_HEIGHT } })
    x += width + CHIP_GAP
  }
  put(`title:${phase.key}`, 'title', title, null, textWidth(title), PHASE_PAD)
  put(
    `mode:${phase.key}`,
    'mode',
    phase.mode,
    null,
    textWidth(phase.mode) + 2 * CHIP_PAD_X,
    PHASE_PAD,
  )
  let right = x - CHIP_GAP
  let y = PHASE_PAD
  x = PHASE_PAD
  for (const hook of phase.hooks) {
    const width = textWidth(`${hook.label} · ${String(hook.steps)}`) + 2 * CHIP_PAD_X
    if (x > PHASE_PAD && x + width > HEADER_MAX_WIDTH) {
      x = PHASE_PAD
      y += CHIP_HEIGHT + CHIP_GAP
    } else if (y === PHASE_PAD) {
      // The first chip row sits under the title row.
      y = PHASE_PAD + CHIP_HEIGHT + CHIP_GAP
    }
    put(`hook:${phase.key}:${hook.hook}`, 'hook', hook.label, hook.steps, width, y)
    right = Math.max(right, x - CHIP_GAP)
  }
  const bottom = Math.max(...items.map((item) => item.rect.y + item.rect.height))

  return { items, width: right - PHASE_PAD, height: bottom + PHASE_PAD }
}

interface Placed {
  phase: LayoutPhase
  nodes: LayoutNode[]
  edges: LayoutEdge[]
}

/** Puts a measured phase at `top`, `width` wide, and routes the edges only this module knows. */
function placePhase(item: Measured, width: number, top: number): Placed {
  const key = item.phase.key
  const hookEdges = item.resolved.filter((entry) => entry.transition.from.type === 'hook')
  const exitEdges = item.resolved.filter((entry) => entry.route === 'exit')
  const loops = new Map<string, number>()
  for (const entry of item.resolved) {
    if (entry.route === 'self') {
      loops.set(entry.from, (loops.get(entry.from) ?? 0) + 1)
    }
  }
  const loopCount = Math.max(0, ...loops.values())
  const loopSpace = loopCount === 0 ? 0 : LOOP_BASE + LOOP_STEP * (loopCount - 1) + LANE_GAP
  const hookSpace = hookEdges.length === 0 ? 0 : LANE_GAP * (hookEdges.length + 1)
  const exitSpace = exitEdges.length === 0 ? 0 : LANE_GAP * (exitEdges.length + 1)

  const headerBottom = top + item.headerHeight
  const innerTop = headerBottom + hookSpace + loopSpace + INNER_GAP
  const innerBottom = innerTop + item.innerHeight
  const rect: LayoutRect = {
    x: 0,
    y: top,
    width,
    height: innerBottom + exitSpace + PHASE_PAD - top,
  }
  const portX = width / 2
  const boxBottom = rect.y + rect.height
  const shift = (point: LayoutPoint): LayoutPoint => ({
    x: point.x + PHASE_PAD,
    y: point.y + innerTop,
  })

  const nodes: LayoutNode[] = item.nodes.map((node) => {
    const box = found(item.inner.get(node.id), node.id)
    const at = shift(box)

    return {
      id: node.id,
      kind: node.kind,
      label: node.label,
      rect: { x: at.x, y: at.y, width: box.width, height: box.height },
      phase: key,
      reached: node.reached,
    }
  })
  const header = item.header.map((entry) => ({
    ...entry,
    rect: { ...entry.rect, y: entry.rect.y + top },
  }))
  const rects = new Map<string, LayoutRect>([
    ...nodes.map((node): [string, LayoutRect] => [node.id, node.rect]),
    ...header.map((entry): [string, LayoutRect] => [entry.id, entry.rect]),
  ])
  const rectOf = (id: string): LayoutRect => found(rects.get(id), id)

  const edges: LayoutEdge[] = []
  for (const edge of item.innerEdgeList) {
    const label = item.innerLabels.get(edge.id)
    edges.push({
      id: edge.id,
      kind: edge.kind,
      points: (item.innerEdges.get(edge.id) ?? []).map(shift),
      ...(label === undefined || edge.label === null
        ? {}
        : { label: { text: edge.label, ...shift(label) } }),
      dashed: edge.id.startsWith('transition:') && edge.label !== null,
    })
  }

  const hookLane = (entry: Resolved): number =>
    headerBottom + LANE_GAP * (hookEdges.indexOf(entry) + 1)
  const exitLane = (entry: Resolved): number =>
    innerBottom + LANE_GAP * (exitEdges.indexOf(entry) + 1)
  for (const entry of item.resolved) {
    if (entry.route === 'dagre') {
      continue
    }
    const source = rectOf(entry.from)
    const start = { x: source.x + source.width / 2, y: source.y + source.height }
    let points: LayoutPoint[]
    if (entry.route === 'self') {
      const turn = loops.get(entry.from) ?? 1
      loops.set(entry.from, turn - 1)
      const lift = LOOP_BASE + LOOP_STEP * (turn - 1)
      const left = source.x + source.width * 0.35
      const right = source.x + source.width * 0.65
      points = [
        { x: right, y: source.y },
        { x: right, y: source.y - lift },
        { x: left, y: source.y - lift },
        { x: left, y: source.y },
      ]
    } else if (entry.route === 'hook') {
      // Down to the lane under the header, along it to the right gutter, then into the Scene
      // Type from its right side, so the arrow never runs through the nodes above it.
      const target = rectOf(
        found(entry.to ?? undefined, `target of transition ${String(entry.index)}`),
      )
      const gutter = width - PHASE_PAD / 2 + GUTTER_STEP * hookEdges.indexOf(entry)
      const targetY = target.y + target.height / 2
      points = [
        start,
        { x: start.x, y: hookLane(entry) },
        { x: gutter, y: hookLane(entry) },
        { x: gutter, y: targetY },
        { x: target.x + target.width, y: targetY },
      ]
    } else if (entry.transition.from.type === 'hook') {
      const gutter = width - PHASE_PAD / 2 + GUTTER_STEP * hookEdges.indexOf(entry)
      points = [
        start,
        { x: start.x, y: hookLane(entry) },
        { x: gutter, y: hookLane(entry) },
        { x: gutter, y: exitLane(entry) },
        { x: portX, y: exitLane(entry) },
        { x: portX, y: boxBottom },
      ]
    } else {
      points = [
        start,
        { x: start.x, y: exitLane(entry) },
        { x: portX, y: exitLane(entry) },
        { x: portX, y: boxBottom },
      ]
    }
    const via = entry.transition.via
    const path = withoutRepeats(points)
    edges.push({
      id: `transition:${String(entry.index)}`,
      kind: entry.transition.kind,
      points: path,
      ...(via === null ? {} : { label: { text: via.name, ...labelSpot(path) } }),
      dashed: via !== null,
    })
  }
  edges.sort((a, b) => edgeOrder(a) - edgeOrder(b) || (a.id < b.id ? -1 : 1))

  return {
    phase: { id: `phase:${key}`, key, name: item.phase.name, rect, header },
    nodes,
    edges,
  }
}

/** Selection edges first, then transitions in diagram order, so the drawing order never depends on routing. */
function edgeOrder(edge: LayoutEdge): number {
  return edge.id.startsWith('transition:') ? 1 + Number(edge.id.split(':')[1]) : 0
}

/** The straight pieces of a path, in order. */
function segments(points: LayoutPoint[]): [LayoutPoint, LayoutPoint][] {
  const pieces: [LayoutPoint, LayoutPoint][] = []
  let previous: LayoutPoint | undefined
  for (const point of points) {
    if (previous !== undefined) {
      pieces.push([previous, point])
    }
    previous = point
  }

  return pieces
}

/** Where to write an edge label: the middle of the path. */
function labelSpot(points: LayoutPoint[]): LayoutPoint {
  const pieces = segments(points)
  const total = pieces.reduce((sum, [from, to]) => sum + distance(from, to), 0)
  let walked = 0
  for (const [from, to] of pieces) {
    const length = distance(from, to)
    if (length > 0 && walked + length >= total / 2) {
      const ratio = (total / 2 - walked) / length

      return { x: from.x + (to.x - from.x) * ratio, y: from.y + (to.y - from.y) * ratio }
    }
    walked += length
  }

  return points[0] ?? { x: 0, y: 0 }
}

function distance(a: LayoutPoint, b: LayoutPoint): number {
  return Math.abs(a.x - b.x) + Math.abs(a.y - b.y)
}

function withoutRepeats(points: LayoutPoint[]): LayoutPoint[] {
  return points.filter((point, index) => {
    const previous = points[index - 1]

    return !(previous?.x === point.x && previous.y === point.y)
  })
}

/** A value the layout itself put in a map; a miss is a defect, reported like malformed content. */
function found<T>(value: T | undefined, what: string): T {
  if (value === undefined) {
    throw new FlowDiagramError('layout', `${what} was not laid out`)
  }

  return value
}

function connector(phaseKey: string, fromY: number, toY: number, x: number): LayoutEdge {
  return {
    id: `connector:${phaseKey}`,
    kind: 'phase',
    points: [
      { x, y: fromY },
      { x, y: toY },
    ],
    dashed: false,
  }
}

function innerNode(id: string, kind: LayoutNodeKind, label: string, reached: boolean): InnerNode {
  return { id, kind, label, reached, width: nodeWidth(label) }
}

function sceneId(phaseKey: string, sceneTypeKey: string): string {
  return `scene:${phaseKey}:${sceneTypeKey}`
}

function nodeWidth(label: string): number {
  return textWidth(label) + 2 * NODE_PAD_X
}

function edgeLabelWidth(label: string): number {
  return label.length * EDGE_LABEL_CHAR_WIDTH
}

function textWidth(text: string): number {
  return text.length * CHAR_WIDTH
}
