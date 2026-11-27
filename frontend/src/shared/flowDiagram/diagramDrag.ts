import { useCallback, useEffect, useRef, useState, type PointerEvent, type RefObject } from 'react'

import type { FlowDiagramLayout, LayoutPoint, LayoutRect } from './layout'

/** How far (px on screen) the pointer must move before a press is a drag; less stays a click. */
export const DRAG_THRESHOLD = 4

/** What lies under a point of the drawing: a node, a header item or a bare phase box. */
export interface DiagramHit {
  id: string
  kind: 'node' | 'header' | 'phase'
  /** The key of the phase box the point is in. */
  phase: string | null
}

const inside = (rect: LayoutRect, point: LayoutPoint): boolean =>
  point.x >= rect.x &&
  point.x <= rect.x + rect.width &&
  point.y >= rect.y &&
  point.y <= rect.y + rect.height

/** The most specific thing at `point` (SVG coordinates): a node, else a header item, else the phase box. Null on bare canvas. */
export function hitTestDiagram(layout: FlowDiagramLayout, point: LayoutPoint): DiagramHit | null {
  const box = layout.phases.find((phase) => inside(phase.rect, point))
  const node = layout.nodes.find((candidate) => inside(candidate.rect, point))
  if (node !== undefined) {
    return { id: node.id, kind: 'node', phase: box?.key ?? node.phase }
  }
  if (box === undefined) {
    return null
  }
  const item = box.header.find((candidate) => inside(candidate.rect, point))
  return item === undefined
    ? { id: box.id, kind: 'phase', phase: box.key }
    : { id: item.id, kind: 'header', phase: box.key }
}

/** The matrix `SVGGraphicsElement.getScreenCTM()` returns: SVG coordinates to client coordinates. */
export interface ScreenMatrix {
  a: number
  b: number
  c: number
  d: number
  e: number
  f: number
}

/** A client point in SVG coordinates (the inverse of the matrix); null when the matrix cannot be inverted. */
export function clientToSvg(matrix: ScreenMatrix, x: number, y: number): LayoutPoint | null {
  const determinant = matrix.a * matrix.d - matrix.b * matrix.c
  if (determinant === 0) {
    return null
  }
  const dx = x - matrix.e
  const dy = y - matrix.f
  return {
    x: (matrix.d * dx - matrix.c * dy) / determinant,
    y: (matrix.a * dy - matrix.b * dx) / determinant,
  }
}

/** Where a drag was let go. `hit` is null on bare canvas. */
export interface DiagramDrop {
  sourceId: string
  hit: DiagramHit | null
  point: LayoutPoint
}

/** A drag in progress, for the drawing to show. */
export interface DragState {
  sourceId: string
  /** What the ghost says when the source is not part of the drawing (a palette item). */
  label: string
  point: LayoutPoint
  /** Where in the drawing the drag began. */
  grab: LayoutPoint
  hit: DiagramHit | null
}

interface Pending {
  sourceId: string
  label: string
  pointerId: number
  clientX: number
  clientY: number
  element: Element
  active: boolean
  grab: LayoutPoint
}

export interface DiagramDrag {
  svgRef: RefObject<SVGSVGElement | null>
  state: DragState | null
  canDrag: (id: string) => boolean
  /** The pointer handlers that make an element the drag source `sourceId`. */
  bind: (sourceId: string, label?: string) => DragHandlers
  /** True once after a drag ended: the click the browser sends then is not an activation. */
  consumeClick: () => boolean
}

export interface DragHandlers {
  onPointerDown: (event: PointerEvent) => void
  onPointerMove: (event: PointerEvent) => void
  onPointerUp: (event: PointerEvent) => void
  onPointerCancel: (event: PointerEvent) => void
  style: { touchAction: 'none' }
}

/**
 * Pointer-event dragging over a drawing, with no library. A press becomes a drag after
 * `DRAG_THRESHOLD` px; the pointer is captured, so the source keeps the moves; the drop is
 * hit-tested on `layout` in SVG coordinates (the drawing's `svgRef` gives the matrix). Escape cancels.
 * Sources may be inside the drawing or outside it, as a palette item is.
 */
export function useDiagramDrag({
  layout,
  canDrag,
  onDrop,
}: {
  layout: FlowDiagramLayout | null
  canDrag: (id: string) => boolean
  onDrop: (drop: DiagramDrop) => void
}): DiagramDrag {
  const svgRef = useRef<SVGSVGElement | null>(null)
  const pending = useRef<Pending | null>(null)
  const clicked = useRef(false)
  const [state, setState] = useState<DragState | null>(null)

  const toSvg = useCallback((event: PointerEvent): LayoutPoint | null => {
    // jsdom has no layout, and a drawing that is not on screen has no matrix: no point either way.
    // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition
    const matrix = svgRef.current?.getScreenCTM?.()
    return matrix ? clientToSvg(matrix, event.clientX, event.clientY) : null
  }, [])

  const stop = useCallback(() => {
    const current = pending.current
    pending.current = null
    setState(null)
    if (current?.active) {
      clicked.current = true
      // The click belongs to this gesture: a drag that ends with none (Escape, a cancelled pointer,
      // a release off the source) must not swallow the click of the next press, on any node.
      window.addEventListener(
        'pointerdown',
        () => {
          clicked.current = false
        },
        { capture: true, once: true },
      )
      try {
        current.element.releasePointerCapture(current.pointerId)
      } catch {
        // The capture is gone already (a cancelled pointer): nothing to release.
      }
    }
  }, [])

  const dragging = state !== null
  useEffect(() => {
    if (!dragging) {
      return undefined
    }
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        stop()
      }
    }
    window.addEventListener('keydown', onKey)
    return () => {
      window.removeEventListener('keydown', onKey)
    }
  }, [dragging, stop])

  const bind = (sourceId: string, label = sourceId): DragHandlers => ({
    style: { touchAction: 'none' },
    onPointerDown: (event) => {
      if (event.button !== 0 || !event.isPrimary) {
        return
      }
      clicked.current = false
      pending.current = {
        sourceId,
        label,
        pointerId: event.pointerId,
        clientX: event.clientX,
        clientY: event.clientY,
        element: event.currentTarget,
        active: false,
        grab: toSvg(event) ?? { x: 0, y: 0 },
      }
    },
    onPointerMove: (event) => {
      const current = pending.current
      if (current?.pointerId !== event.pointerId) {
        return
      }
      if (!current.active) {
        const moved = Math.hypot(event.clientX - current.clientX, event.clientY - current.clientY)
        if (moved < DRAG_THRESHOLD) {
          return
        }
        current.active = true
        try {
          current.element.setPointerCapture(event.pointerId)
        } catch {
          // A pointer that is gone cannot be captured; the drag goes on with the moves it gets.
        }
      }
      const point = toSvg(event)
      if (point !== null) {
        setState({
          sourceId: current.sourceId,
          label: current.label,
          grab: current.grab,
          point,
          hit: layout === null ? null : hitTestDiagram(layout, point),
        })
      }
    },
    onPointerUp: (event) => {
      const current = pending.current
      if (current?.pointerId !== event.pointerId || !current.active) {
        pending.current = null
        return
      }
      const point = toSvg(event)
      stop()
      if (point !== null && layout !== null) {
        onDrop({ sourceId: current.sourceId, hit: hitTestDiagram(layout, point), point })
      }
    },
    onPointerCancel: () => {
      stop()
    },
  })

  const consumeClick = () => {
    const was = clicked.current
    clicked.current = false
    return was
  }

  return { svgRef, state, canDrag, bind, consumeClick }
}
