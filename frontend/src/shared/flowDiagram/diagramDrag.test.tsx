import { fireEvent, render, screen } from '@testing-library/react'
import { useMemo } from 'react'

import { clientToSvg, hitTestDiagram, useDiagramDrag, type DiagramDrop } from './diagramDrag'
import { FlowDiagramView } from './FlowDiagramView'
import { flowDiagramFromReleaseContent } from './fromReleaseContent'
import { layoutFlowDiagram } from './layout'
import { phaseDiagram } from './phaseDiagram'
import { loadPreset } from './testSupport'

const ship = phaseDiagram(
  flowDiagramFromReleaseContent(loadPreset('dogwatch'), 'cold-berth'),
  'the-ship',
)
const layout = layoutFlowDiagram(ship)
const centre = ({
  x,
  y,
  width,
  height,
}: {
  x: number
  y: number
  width: number
  height: number
}) => ({
  clientX: x + width / 2,
  clientY: y + height / 2,
})
const box = layout.phases[0]
const scene = layout.nodes.find(({ kind }) => kind === 'sceneType')
if (box === undefined || scene === undefined) {
  throw new Error('Expected a phase with a Scene Type.')
}

describe('hitTestDiagram', () => {
  it('finds the node, else the header item, else the phase box, else nothing', () => {
    const chip = box.header.find(({ kind }) => kind === 'mode')
    const bare = { x: box.rect.x + 1, y: box.rect.y + box.rect.height - 1 }

    expect(hitTestDiagram(layout, { x: scene.rect.x + 2, y: scene.rect.y + 2 })).toEqual({
      id: scene.id,
      kind: 'node',
      phase: box.key,
    })
    expect(
      hitTestDiagram(layout, { x: (chip?.rect.x ?? 0) + 1, y: (chip?.rect.y ?? 0) + 1 }),
    ).toEqual({
      id: chip?.id,
      kind: 'header',
      phase: box.key,
    })
    expect(hitTestDiagram(layout, bare)).toEqual({ id: box.id, kind: 'phase', phase: box.key })
    expect(hitTestDiagram(layout, { x: -5, y: -5 })).toBeNull()
  })
})

describe('clientToSvg', () => {
  it('undoes a scale and an offset', () => {
    expect(clientToSvg({ a: 2, b: 0, c: 0, d: 2, e: 10, f: 20 }, 50, 60)).toEqual({ x: 20, y: 20 })
  })

  it('is null for a matrix that cannot be inverted', () => {
    expect(clientToSvg({ a: 0, b: 0, c: 0, d: 0, e: 0, f: 0 }, 1, 1)).toBeNull()
  })
})

function Harness({
  onDrop,
  onActivate,
}: {
  onDrop: (drop: DiagramDrop) => void
  onActivate: () => void
}) {
  const canDrag = (id: string) => id === box?.id
  const drag = useDiagramDrag({ layout: useMemo(() => layout, []), canDrag, onDrop })
  return <FlowDiagramView diagram={ship} onActivate={onActivate} drag={drag} />
}

const press = { pointerId: 1, isPrimary: true, button: 0 }

function setup() {
  const onDrop = vi.fn()
  const onActivate = vi.fn()
  const { container } = render(<Harness onDrop={onDrop} onActivate={onActivate} />)
  // jsdom has no layout: the drawing's matrix is the identity.
  const svg = container.querySelector('svg')
  if (svg === null) {
    throw new Error('Expected the drawing.')
  }
  svg.getScreenCTM = () => ({ a: 1, b: 0, c: 0, d: 1, e: 0, f: 0 }) as DOMMatrix
  const source = screen.getByRole('button', { name: 'Phase: The Ship' })
  return { container, source, onDrop, onActivate }
}

describe('useDiagramDrag', () => {
  it('drops on what is under the pointer once the move passes the threshold', () => {
    const { container, source, onDrop } = setup()
    const from = centre(box.rect)

    fireEvent.pointerDown(source, { ...press, ...from })
    fireEvent.pointerMove(source, { ...press, clientX: from.clientX + 2, clientY: from.clientY })
    expect(container.querySelector('[data-drag-ghost]')).toBeNull()

    fireEvent.pointerMove(source, { ...press, ...centre(scene.rect) })
    expect(container.querySelector('[data-drag-ghost]')).not.toBeNull()
    expect(container.querySelector(`[data-drop-target="${box.id}"]`)).not.toBeNull()
    fireEvent.pointerUp(source, { ...press, ...centre(scene.rect) })

    expect(onDrop).toHaveBeenCalledTimes(1)
    expect(onDrop.mock.calls[0]?.[0]).toMatchObject({
      sourceId: box.id,
      hit: { id: scene.id, kind: 'node', phase: box.key },
    })
    expect(container.querySelector('[data-drag-ghost]')).toBeNull()
  })

  it('says the drop is on nothing over bare canvas', () => {
    const { source, onDrop } = setup()

    fireEvent.pointerDown(source, { ...press, ...centre(box.rect) })
    fireEvent.pointerMove(source, { ...press, clientX: -20, clientY: -20 })
    fireEvent.pointerUp(source, { ...press, clientX: -20, clientY: -20 })

    expect(onDrop.mock.calls[0]?.[0]).toMatchObject({ sourceId: box.id, hit: null })
  })

  it('keeps a press that barely moved as a click, and swallows the click a drag leaves', () => {
    const { source, onDrop, onActivate } = setup()
    const from = centre(box.rect)

    fireEvent.pointerDown(source, { ...press, ...from })
    fireEvent.pointerMove(source, { ...press, clientX: from.clientX + 3, clientY: from.clientY })
    fireEvent.pointerUp(source, { ...press, ...from })
    fireEvent.click(source)
    expect(onDrop).not.toHaveBeenCalled()
    expect(onActivate).toHaveBeenCalledTimes(1)

    fireEvent.pointerDown(source, { ...press, ...from })
    fireEvent.pointerMove(source, { ...press, ...centre(scene.rect) })
    fireEvent.pointerUp(source, { ...press, ...centre(scene.rect) })
    fireEvent.click(source)
    expect(onDrop).toHaveBeenCalledTimes(1)
    expect(onActivate).toHaveBeenCalledTimes(1)
  })

  it('cancels on Escape, so the release drops nothing', () => {
    const { container, source, onDrop } = setup()

    fireEvent.pointerDown(source, { ...press, ...centre(box.rect) })
    fireEvent.pointerMove(source, { ...press, ...centre(scene.rect) })
    fireEvent.keyDown(window, { key: 'Escape' })
    fireEvent.pointerUp(source, { ...press, ...centre(scene.rect) })

    expect(onDrop).not.toHaveBeenCalled()
    expect(container.querySelector('[data-drag-ghost]')).toBeNull()
  })

  it('swallows only the click of its own gesture: a drag that ends without one leaves the next click alone', () => {
    const { source, onDrop, onActivate } = setup()
    const [node] = screen.getAllByRole('button', { name: /^Scene Type: / })
    if (node === undefined) {
      throw new Error('Expected a Scene Type node.')
    }

    fireEvent.pointerDown(source, { ...press, ...centre(box.rect) })
    fireEvent.pointerMove(source, { ...press, ...centre(scene.rect) })
    fireEvent.keyDown(window, { key: 'Escape' })
    expect(onDrop).not.toHaveBeenCalled()

    // A fresh press and click on a node that cannot be dragged: nothing is left to swallow.
    fireEvent.pointerDown(node, { ...press, ...centre(scene.rect) })
    fireEvent.pointerUp(node, { ...press, ...centre(scene.rect) })
    fireEvent.click(node)
    expect(onActivate).toHaveBeenCalledTimes(1)
  })

  it('ignores a press with another button, and a press on a node that cannot be dragged', () => {
    const { source, onDrop } = setup()

    fireEvent.pointerDown(source, { ...press, button: 2, ...centre(box.rect) })
    fireEvent.pointerMove(source, { ...press, ...centre(scene.rect) })
    fireEvent.pointerUp(source, { ...press, ...centre(scene.rect) })
    const [node] = screen.getAllByRole('button', { name: /^Scene Type: / })
    if (node === undefined) {
      throw new Error('Expected a Scene Type node.')
    }
    fireEvent.pointerDown(node, press)
    fireEvent.pointerMove(node, { ...press, clientX: 400 })
    fireEvent.pointerUp(node, { ...press, clientX: 400 })

    expect(onDrop).not.toHaveBeenCalled()
  })
})
