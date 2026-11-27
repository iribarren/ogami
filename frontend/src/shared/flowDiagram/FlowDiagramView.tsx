import { useId, type CSSProperties } from 'react'

import { describeFlowDiagram } from './describeFlowDiagram'
import type { FlowDiagram } from './flowDiagram'
import {
  EDGE_LABEL_FONT_SIZE,
  FONT_SIZE,
  type LayoutEdge,
  type LayoutEdgeKind,
  type LayoutHeaderItem,
  type LayoutNode,
  type LayoutPhase,
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
  color: string
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

const theme = (token: string): string => `var(--${token})`

const HALO: CSSProperties = { paintOrder: 'stroke', stroke: theme('background'), strokeWidth: 3 }

export interface FlowDiagramViewProps {
  diagram: FlowDiagram
  className?: string
}

export function FlowDiagramView({ diagram, className }: FlowDiagramViewProps) {
  const layout = layoutFlowDiagram(diagram)
  const description = describeFlowDiagram(diagram)
  const id = useId().replaceAll(':', '')
  const wordsId = `${id}-words`
  const markerId = (marker: Marker): string => `${id}-${marker}`

  return (
    <figure className={className}>
      <svg
        role="img"
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
          <PhaseBox key={phase.id} phase={phase} />
        ))}
        {layout.edges.map((edge) => (
          <EdgePath key={edge.id} edge={edge} markerId={markerId} />
        ))}
        {layout.nodes.map((node) => (
          <NodeShape key={node.id} node={node} />
        ))}
      </svg>
      <details className="mt-2 text-sm">
        <summary className="cursor-pointer">The flow in words</summary>
        <div id={wordsId} className="mt-2 space-y-2">
          <ol className="list-decimal space-y-1 pl-5">
            {description.phases.map((phase) => (
              <li key={phase.title}>
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
                {description.transitions.map((transition) => (
                  <li key={transition}>{transition}</li>
                ))}
              </ul>
            </>
          )}
        </div>
      </details>
    </figure>
  )
}

function PhaseBox({ phase }: { phase: LayoutPhase }) {
  const { rect } = phase

  return (
    <g data-phase-id={phase.id}>
      <rect
        {...rect}
        rx={10}
        style={{ fill: theme('card'), stroke: theme('border') }}
        strokeWidth={1.5}
      />
      {phase.header.map((item) => (
        <HeaderItem key={item.id} item={item} />
      ))}
    </g>
  )
}

function HeaderItem({ item }: { item: LayoutHeaderItem }) {
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

  return (
    <g data-header-id={item.id}>
      <rect
        {...rect}
        rx={item.kind === 'mode' ? rect.height / 2 : 4}
        style={{
          fill: theme(item.kind === 'mode' ? 'muted' : 'accent'),
          stroke: theme('border'),
        }}
      />
      {text}
    </g>
  )
}

function NodeShape({ node }: { node: LayoutNode }) {
  const { rect } = node
  const centre = { x: rect.x + rect.width / 2, y: rect.y + rect.height / 2 }
  const pill = node.kind === 'choice' || node.kind === 'end' || node.kind === 'exitStub'
  // A reached Scene Type has a second line saying so, under the name.
  const nameY = node.reached ? centre.y - 5 : centre.y

  return (
    <g data-node-id={node.id} data-kind={node.kind}>
      {node.reached && <title>{`${node.label}: reached by a transition`}</title>}
      <rect
        {...rect}
        rx={pill ? rect.height / 2 : 6}
        strokeDasharray={node.reached || node.kind === 'exitStub' ? '4 3' : undefined}
        strokeWidth={node.kind === 'table' ? 2 : 1.5}
        style={{
          fill: theme(node.kind === 'sceneType' || node.kind === 'end' ? 'background' : 'muted'),
          stroke: theme('foreground'),
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
          textAnchor="middle"
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
