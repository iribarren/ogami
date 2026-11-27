import { useId, type CSSProperties, type KeyboardEvent, type MouseEvent } from 'react'

import { describeFlowDiagram } from './describeFlowDiagram'
import { type DiagramToken } from './diagramTokens'
import type { DiagramIssues, FlowDiagram } from './flowDiagram'
import {
  EDGE_LABEL_FONT_SIZE,
  FONT_SIZE,
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
export type DiagramActivation = 'phase' | 'choice' | 'table' | 'sceneType' | 'hook'

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
}

/** What the interactive parts of the drawing share. */
interface Interaction {
  selectedId: string | undefined
  issues: DiagramIssues
  onActivate: ((id: string, kind: DiagramActivation) => void) | undefined
}

/** The button behaviour of a node: none while the drawing is static. */
function activation(interaction: Interaction, id: string, kind: DiagramActivation, label: string) {
  const { onActivate, issues, selectedId } = interaction
  if (onActivate === undefined) {
    return {}
  }
  const messages = issues[id] ?? []

  return {
    role: 'button',
    tabIndex: 0,
    'aria-label': messages.length === 0 ? label : `${label}. Issues: ${messages.join(' ')}`,
    'aria-current': selectedId === id ? ('true' as const) : undefined,
    className: 'cursor-pointer outline-none focus-visible:outline-2 focus-visible:outline-primary',
    onClick: (event: MouseEvent) => {
      event.stopPropagation()
      onActivate(id, kind)
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
}: FlowDiagramViewProps) {
  const interaction: Interaction = { selectedId, issues, onActivate }
  const layout = layoutFlowDiagram(diagram)
  const description = describeFlowDiagram(diagram)
  const id = useId().replaceAll(':', '')
  const wordsId = `${id}-words`
  const markerId = (marker: Marker): string => `${id}-${marker}`

  return (
    <figure className={className}>
      <svg
        // An image hides its children from assistive technology: with buttons in it, it is a group.
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
  const { onClick, ...button } = activation(interaction, phase.id, 'phase', `Phase: ${phase.name}`)

  return (
    // A click anywhere in the box (its title included) opens it; the chips stop theirs.
    <g data-phase-id={phase.id} onClick={onClick}>
      <rect
        {...rect}
        {...button}
        rx={10}
        style={{ fill: theme('card'), stroke: theme(look.color) }}
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
    return <g data-header-id={item.id}>{text}</g>
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
      : {}

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
    </g>
  )
}

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
