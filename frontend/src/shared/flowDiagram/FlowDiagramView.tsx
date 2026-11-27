import { useId, type CSSProperties, type KeyboardEvent, type MouseEvent } from 'react'

import { describeFlowDiagram } from './describeFlowDiagram'
import { type DiagramToken } from './diagramTokens'
import type { DiagramDrag, DragHandlers, DragState } from './diagramDrag'
import type { DiagramIssues, FlowDiagram } from './flowDiagram'
import {
  CHAR_WIDTH,
  EDGE_LABEL_FONT_SIZE,
  FONT_SIZE,
  type FlowDiagramLayout,
  type LayoutEdge,
  type LayoutEdgeKind,
  type LayoutHeaderItem,
  type LayoutNode,
  type LayoutPhase,
  type LayoutRect,
  layoutFlowDiagram,
} from './layout'

/**
 * A flow diagram as an inline SVG with its text alternative beside it. Both are built from the same
 * FlowDiagram. Colours come only from the theme CSS variables, so the drawing follows light and dark
 * and the print stylesheet can override them; the kinds of arrow differ by marker, weight and dash as
 * well, never by colour alone.
 */

type Marker = 'arrow' | 'diamond' | 'open'

interface EdgeStyle {
  color: DiagramToken
  width: number
  marker: Marker
}

const EDGE_STYLES: Record<LayoutEdgeKind, EdgeStyle> = {
  selection: { color: 'muted-foreground', width: 1, marker: 'open' },
  nextScene: { color: 'foreground', width: 1.5, marker: 'arrow' },
  switchSceneType: { color: 'info', width: 1.5, marker: 'diamond' },
  endPhase: { color: 'primary', width: 2, marker: 'arrow' },
  phase: { color: 'muted-foreground', width: 2, marker: 'arrow' },
}

const MARKER_PATHS: Record<Marker, string> = {
  arrow: 'M0,0 L10,5 L0,10 z',
  diamond: 'M0,5 L5,0 L10,5 L5,10 z',
  open: 'M0,1 L9,5 L0,9',
}

const theme = (token: DiagramToken): string => `var(--${token})`

const HALO: CSSProperties = { paintOrder: 'stroke', stroke: theme('background'), strokeWidth: 3 }

/** What a node is, as `onActivate` says it: a phase box, a selection or Scene Type node, or a hook chip. */
export type DiagramActivation = 'phase' | 'choice' | 'table' | 'sceneType' | 'hook' | 'mode'

export interface FlowDiagramViewProps {
  diagram: FlowDiagram
  className?: string
  /** The id of the node drawn as selected (`phase:<key>`, `scene:<phase>:<sceneType>`, `hook:<phase>:<hook>`...). */
  selectedId?: string | undefined
  /** Messages by node id: a node with some is marked, and its accessible name lists them. */
  issues?: DiagramIssues
  /**
   * Makes phase boxes, selection nodes, Scene Type nodes and hook chips buttons (focusable; Enter,
   * Space and click). Without it the drawing is static, as the manual and print edition use it.
   */
  onActivate?: (id: string, kind: DiagramActivation) => void
  /** Accessible names by node id, in place of the default one (the mode chip says what it switches to). */
  labels?: Record<string, string>
  /** Told which node was focused or pressed last, for what acts on "the node you are on". */
  onFocusNode?: (id: string, kind: DiagramActivation) => void
  /** Makes the nodes `drag.canDrag` allows draggable, and draws the ghost and the drop target. */
  drag?: DiagramDrag
  /**
   * Draws a port on the right edge of every Scene Type node, hook chip and selection table node:
   * pressing one and dragging draws an arrow (it needs `drag`). Pointer only, so ports are out of the
   * tab order and hidden from assistive technology; the host offers the same through its own controls.
   */
  arrows?: boolean
}

/** What the interactive parts of the drawing share. */
interface Interaction {
  selectedId: string | undefined
  issues: DiagramIssues
  onActivate: ((id: string, kind: DiagramActivation) => void) | undefined
  labels: Record<string, string>
  onFocusNode: ((id: string, kind: DiagramActivation) => void) | undefined
  drag: DiagramDrag | undefined
  arrows: boolean
}

/** What makes a node of the drawing a button: nothing while the drawing is static. */
type ButtonProps = Partial<DragHandlers> & {
  role?: 'button'
  tabIndex?: number
  'aria-label'?: string
  'aria-current'?: 'true'
  className?: string
  onFocus?: () => void
  onPointerDownCapture?: () => void
  onClick?: (event: MouseEvent) => void
  onKeyDown?: (event: KeyboardEvent) => void
}

/** The button behaviour of a node: none while the drawing is static. */
function activation(
  interaction: Interaction,
  id: string,
  kind: DiagramActivation,
  label: string,
): ButtonProps {
  const { onActivate, issues, selectedId, drag, onFocusNode } = interaction
  if (onActivate === undefined) {
    return {}
  }
  const messages = issues[id] ?? []
  const name = interaction.labels[id] ?? label

  return {
    role: 'button',
    tabIndex: 0,
    'aria-label': messages.length === 0 ? name : `${name}. Issues: ${messages.join(' ')}`,
    'aria-current': selectedId === id ? ('true' as const) : undefined,
    className: 'cursor-pointer outline-none focus-visible:outline-2 focus-visible:outline-primary',
    ...(drag?.canDrag(id) ? drag.bind(id, label) : {}),
    onFocus: () => onFocusNode?.(id, kind),
    onPointerDownCapture: () => onFocusNode?.(id, kind),
    onClick: (event: MouseEvent) => {
      event.stopPropagation()
      if (!drag?.consumeClick()) {
        onActivate(id, kind)
      }
    },
    onKeyDown: (event: KeyboardEvent) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault()
        onActivate(id, kind)
      }
    },
  }
}

/** How a box is outlined: selected (thick, primary) over issues (destructive) over its own look. */
function outline(
  interaction: Interaction,
  id: string,
  own: { color: DiagramToken; width: number },
): { color: DiagramToken; width: number } {
  if (interaction.selectedId === id) {
    return { color: 'primary', width: 3 }
  }
  if ((interaction.issues[id] ?? []).length > 0) {
    return { color: 'destructive', width: 2.5 }
  }

  return own
}

export function FlowDiagramView({
  diagram,
  className,
  selectedId,
  issues = {},
  onActivate,
  labels = {},
  onFocusNode,
  drag,
  arrows = false,
}: FlowDiagramViewProps) {
  const interaction: Interaction = {
    selectedId,
    issues,
    onActivate,
    labels,
    onFocusNode,
    drag,
    arrows,
  }
  const layout = layoutFlowDiagram(diagram)
  const description = describeFlowDiagram(diagram)
  const id = useId().replaceAll(':', '')
  const wordsId = `${id}-words`
  const markerId = (marker: Marker): string => `${id}-${marker}`

  return (
    <figure className={className}>
      <svg
        // An image hides its children from assistive technology: with buttons in it, it is a group.
        ref={drag?.svgRef}
        role={onActivate === undefined ? 'img' : 'group'}
        aria-label={`Flow diagram: ${diagram.name}`}
        aria-describedby={wordsId}
        viewBox={`0 0 ${String(layout.width)} ${String(layout.height)}`}
        width={layout.width}
        className="h-auto max-w-full"
        fontFamily="inherit"
      >
        <defs>
          {(Object.keys(MARKER_PATHS) as Marker[]).map((marker) => (
            <marker
              key={marker}
              id={markerId(marker)}
              viewBox="0 0 10 10"
              refX={9}
              refY={5}
              markerWidth={9}
              markerHeight={9}
              markerUnits="userSpaceOnUse"
              orient="auto"
            >
              <path
                d={MARKER_PATHS[marker]}
                style={
                  marker === 'open'
                    ? { fill: 'none', stroke: 'context-stroke', strokeWidth: 1.2 }
                    : { fill: 'context-stroke' }
                }
              />
            </marker>
          ))}
        </defs>
        {layout.phases.map((phase) => (
          <PhaseBox key={phase.id} phase={phase} interaction={interaction} />
        ))}
        {layout.edges.map((edge) => (
          <EdgePath key={edge.id} edge={edge} markerId={markerId} />
        ))}
        {layout.nodes.map((node) => (
          <NodeShape key={node.id} node={node} interaction={interaction} />
        ))}
        {drag?.state != null && (
          <DragLayer state={drag.state} layout={layout} markerId={markerId} />
        )}
      </svg>
      <details className="mt-2 text-sm">
        <summary className="cursor-pointer">The flow in words</summary>
        <div id={wordsId} className="mt-2 space-y-2">
          <ol className="list-decimal space-y-1 pl-5">
            {description.phases.map((phase, index) => (
              <li key={`${String(index)}:${phase.title}`}>
                <strong>{phase.title}</strong>. {phase.selection}.
                <ul className="list-disc pl-5">
                  {phase.sceneTypes.length > 0 && (
                    <li>Scene Types: {phase.sceneTypes.join(', ')}</li>
                  )}
                  {phase.hooks.length > 0 && <li>Hooks: {phase.hooks.join(', ')}</li>}
                </ul>
              </li>
            ))}
          </ol>
          {description.continuesTo !== null && (
            <p>The flow continues to: {description.continuesTo}.</p>
          )}
          {description.transitions.length > 0 && (
            <>
              <p className="font-medium">Transitions</p>
              <ul className="list-disc pl-5">
                {description.transitions.map((transition, index) => (
                  <li key={`${String(index)}:${transition}`}>{transition}</li>
                ))}
              </ul>
            </>
          )}
        </div>
      </details>
    </figure>
  )
}

function PhaseBox({ phase, interaction }: { phase: LayoutPhase; interaction: Interaction }) {
  const { rect } = phase
  const look = outline(interaction, phase.id, { color: 'border', width: 1.5 })
  const {
    onClick,
    style: touch,
    ...button
  } = activation(interaction, phase.id, 'phase', `Phase: ${phase.name}`)

  return (
    // A click anywhere in the box (its title included) opens it; the chips stop theirs.
    <g data-phase-id={phase.id} onClick={onClick}>
      <rect
        {...rect}
        {...button}
        rx={10}
        style={{ ...touch, fill: theme('card'), stroke: theme(look.color) }}
        strokeWidth={look.width}
      />
      <IssueBadge rect={rect} marked={(interaction.issues[phase.id] ?? []).length > 0} />
      {phase.header.map((item) => (
        <HeaderItem key={item.id} item={item} interaction={interaction} />
      ))}
    </g>
  )
}

/** The "!" on the corner of a box with issues: the mark does not rely on colour. */
function IssueBadge({ rect, marked }: { rect: LayoutRect; marked: boolean }) {
  if (!marked) {
    return null
  }

  return (
    <g aria-hidden="true" pointerEvents="none">
      <circle
        cx={rect.x + rect.width}
        cy={rect.y}
        r={8}
        style={{ fill: theme('destructive'), stroke: theme('background') }}
        strokeWidth={1.5}
      />
      <text
        x={rect.x + rect.width}
        y={rect.y}
        textAnchor="middle"
        dominantBaseline="central"
        fontSize={11}
        fontWeight={700}
        style={{ fill: theme('background') }}
      >
        !
      </text>
    </g>
  )
}

function HeaderItem({ item, interaction }: { item: LayoutHeaderItem; interaction: Interaction }) {
  const { rect } = item
  const text = (
    <text
      x={item.kind === 'title' ? rect.x : rect.x + rect.width / 2}
      y={rect.y + rect.height / 2}
      textAnchor={item.kind === 'title' ? 'start' : 'middle'}
      dominantBaseline="central"
      fontSize={FONT_SIZE}
      fontWeight={item.kind === 'title' ? 600 : 400}
      style={{ fill: theme('foreground') }}
    >
      {item.kind === 'hook' ? `${item.label} · ${String(item.count)}` : item.label}
    </text>
  )
  if (item.kind === 'title') {
    // The title lets presses through to the box under it, which is the button.
    return (
      <g data-header-id={item.id} pointerEvents={interaction.onActivate ? 'none' : undefined}>
        {text}
      </g>
    )
  }

  const look = outline(interaction, item.id, { color: 'border', width: 1 })
  const button =
    item.kind === 'hook'
      ? activation(
          interaction,
          item.id,
          'hook',
          `Hook: ${item.label}, ${String(item.count)} ${item.count === 1 ? 'step' : 'steps'}`,
        )
      : activation(interaction, item.id, 'mode', `Mode: ${item.label}`)

  return (
    <g data-header-id={item.id} {...button}>
      <rect
        {...rect}
        rx={item.kind === 'mode' ? rect.height / 2 : 4}
        strokeWidth={look.width}
        style={{
          fill: theme(item.kind === 'mode' ? 'muted' : 'accent'),
          stroke: theme(look.color),
        }}
      />
      {text}
      <IssueBadge rect={rect} marked={(interaction.issues[item.id] ?? []).length > 0} />
      {item.kind === 'hook' && <Port id={item.id} rect={rect} interaction={interaction} />}
    </g>
  )
}

/** The size of a port: small enough to sit on a chip's edge, big enough to press. */
const PORT_RADIUS = 5

/** Where an arrow leaves a node: the middle of its right edge. */
const portOf = (rect: LayoutRect) => ({ x: rect.x + rect.width, y: rect.y + rect.height / 2 })

/** The circle on a node's right edge an arrow is drawn from; only while the drawing has arrows. */
function Port({
  id,
  rect,
  interaction,
}: {
  id: string
  rect: LayoutRect
  interaction: Interaction
}) {
  const { drag, arrows } = interaction
  if (!arrows || drag === undefined) {
    return null
  }
  const { style: touch, ...handlers } = drag.bind(id, id, 'arrow')
  const at = portOf(rect)

  return (
    <circle
      data-port-id={id}
      aria-hidden="true"
      cx={at.x}
      cy={at.y}
      r={PORT_RADIUS}
      className="cursor-crosshair"
      strokeWidth={1.5}
      style={{ ...touch, fill: theme('background'), stroke: theme('primary') }}
      {...handlers}
      // A press on a port is not a press on its node: it opens nothing.
      onClick={(event) => {
        event.stopPropagation()
      }}
    />
  )
}

/** The dashed ghost of what is being dragged and the outline of the phase it is over. */
function DragLayer({
  state,
  layout,
  markerId,
}: {
  state: DragState
  layout: FlowDiagramLayout
  markerId: (marker: Marker) => string
}) {
  if (state.kind === 'arrow') {
    return <ArrowLayer state={state} layout={layout} markerId={markerId} />
  }
  const source =
    layout.nodes.find(({ id }) => id === state.sourceId)?.rect ??
    layout.phases.find(({ id }) => id === state.sourceId)?.rect
  const target = layout.phases.find(({ key }) => key === state.hit?.phase)
  const dx = state.point.x - state.grab.x
  const dy = state.point.y - state.grab.y
  const look = { fill: 'none', stroke: theme('primary') }

  return (
    <g aria-hidden="true" pointerEvents="none">
      {target !== undefined && (
        <rect
          data-drop-target={target.id}
          {...target.rect}
          rx={10}
          strokeWidth={3}
          strokeDasharray="6 4"
          style={look}
        />
      )}
      {source === undefined ? (
        <g data-drag-ghost={state.sourceId}>
          <rect
            x={state.point.x - 4}
            y={state.point.y - 12}
            width={textWidthOf(state.label)}
            height={24}
            rx={6}
            strokeWidth={1.5}
            style={{ fill: theme('background'), stroke: theme('primary') }}
          />
          <text
            x={state.point.x + 4}
            y={state.point.y}
            dominantBaseline="central"
            fontSize={FONT_SIZE}
            style={{ fill: theme('foreground') }}
          >
            {state.label}
          </text>
        </g>
      ) : (
        <rect
          data-drag-ghost={state.sourceId}
          {...source}
          x={source.x + dx}
          y={source.y + dy}
          rx={8}
          strokeWidth={2}
          strokeDasharray="2 3"
          opacity={0.8}
          style={look}
        />
      )}
    </g>
  )
}

/** The rubber band from the port being dragged to the pointer, and the node or phase it is over. */
function ArrowLayer({
  state,
  layout,
  markerId,
}: {
  state: DragState
  layout: FlowDiagramLayout
  markerId: (marker: Marker) => string
}) {
  const from =
    layout.nodes.find(({ id }) => id === state.sourceId)?.rect ??
    layout.phases.flatMap(({ header }) => header).find(({ id }) => id === state.sourceId)?.rect
  const over = layout.nodes.find(({ id }) => id === state.hit?.id)?.rect
  const phase = layout.phases.find(({ key }) => key === state.hit?.phase)
  const start = from === undefined ? state.grab : portOf(from)
  const look = { fill: 'none', stroke: theme('primary') }

  return (
    <g aria-hidden="true" pointerEvents="none" data-arrow-from={state.sourceId}>
      {phase !== undefined && (
        <rect
          data-drop-target={phase.id}
          {...phase.rect}
          rx={10}
          strokeWidth={2}
          strokeDasharray="6 4"
          style={look}
        />
      )}
      {over !== undefined && <rect {...over} rx={6} strokeWidth={3} style={look} />}
      <line
        data-arrow-line
        x1={start.x}
        y1={start.y}
        x2={state.point.x}
        y2={state.point.y}
        strokeWidth={2}
        strokeDasharray="5 4"
        markerEnd={`url(#${markerId('arrow')})`}
        style={{ stroke: theme('primary') }}
      />
    </g>
  )
}

const textWidthOf = (text: string): number => text.length * CHAR_WIDTH + 16

const NODE_ACTIVATION: Partial<Record<LayoutNode['kind'], [DiagramActivation, string]>> = {
  sceneType: ['sceneType', 'Scene Type'],
  choice: ['choice', 'Selection'],
  table: ['table', 'Selection table'],
}

function NodeShape({ node, interaction }: { node: LayoutNode; interaction: Interaction }) {
  const { rect } = node
  const look = outline(interaction, node.id, {
    color: 'foreground',
    width: node.kind === 'table' ? 2 : 1.5,
  })
  const target = NODE_ACTIVATION[node.kind]
  const button =
    target === undefined
      ? {}
      : activation(interaction, node.id, target[0], `${target[1]}: ${node.label}`)
  const centre = { x: rect.x + rect.width / 2, y: rect.y + rect.height / 2 }
  const pill = node.kind === 'choice' || node.kind === 'end' || node.kind === 'exitStub'
  // A reached Scene Type has a second line saying so, under the name.
  const nameY = node.reached ? centre.y - 5 : centre.y

  return (
    <g data-node-id={node.id} data-kind={node.kind} {...button}>
      {node.reached && <title>{`${node.label}: reached by a transition`}</title>}
      <rect
        {...rect}
        rx={pill ? rect.height / 2 : 6}
        strokeDasharray={node.reached || node.kind === 'exitStub' ? '4 3' : undefined}
        strokeWidth={look.width}
        style={{
          fill: theme(node.kind === 'sceneType' || node.kind === 'end' ? 'background' : 'muted'),
          stroke: theme(look.color),
        }}
      />
      {node.kind === 'table' && (
        <line
          x1={rect.x}
          x2={rect.x + rect.width}
          y1={rect.y + 7}
          y2={rect.y + 7}
          style={{ stroke: theme('foreground') }}
        />
      )}
      <text
        x={centre.x}
        y={nameY}
        textAnchor="middle"
        dominantBaseline="central"
        fontSize={FONT_SIZE}
        fontStyle={node.kind === 'choice' ? 'italic' : undefined}
        style={{ fill: theme('foreground') }}
      >
        {node.label}
      </text>
      {node.reached && (
        <text
          x={centre.x}
          y={centre.y + 9}
          textAnchor="middle"
          dominantBaseline="central"
          fontSize={9}
          style={{ fill: theme('muted-foreground') }}
        >
          reached
        </text>
      )}
      <IssueBadge rect={rect} marked={(interaction.issues[node.id] ?? []).length > 0} />
      {(node.kind === 'sceneType' || node.kind === 'table') && (
        <Port id={node.id} rect={rect} interaction={interaction} />
      )}
    </g>
  )
}

function EdgePath({ edge, markerId }: { edge: LayoutEdge; markerId: (marker: Marker) => string }) {
  const style = EDGE_STYLES[edge.kind]
  const points = edge.points.map((point) => `${String(point.x)},${String(point.y)}`).join(' ')

  return (
    <g data-edge-id={edge.id} data-kind={edge.kind}>
      <polyline
        points={points}
        fill="none"
        strokeWidth={style.width}
        strokeLinejoin="round"
        strokeDasharray={edge.dashed ? '5 4' : undefined}
        markerEnd={`url(#${markerId(style.marker)})`}
        style={{ stroke: theme(style.color) }}
      />
      {edge.label !== undefined && (
        <text
          x={edge.label.x}
          y={edge.label.y}
          textAnchor={edge.label.anchor ?? 'middle'}
          dominantBaseline="central"
          fontSize={EDGE_LABEL_FONT_SIZE}
          style={{ ...HALO, fill: theme('foreground') }}
        >
          {edge.label.text}
        </text>
      )}
    </g>
  )
}
