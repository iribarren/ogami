import { describe, expect, it } from 'vitest'

import type { FlowDiagram } from './flowDiagram'
import { flowDiagramFromReleaseContent } from './fromReleaseContent'
import { type LayoutRect, layoutFlowDiagram } from './layout'
import { phaseDiagram } from './phaseDiagram'
import { loadPreset, phase, release, sceneType, step, table } from './testSupport'

const coldBerth = flowDiagramFromReleaseContent(loadPreset('dogwatch'), 'cold-berth')
const longCarry = flowDiagramFromReleaseContent(loadPreset('hard-country'), 'the-long-carry')
const ironsworn = flowDiagramFromReleaseContent(loadPreset('ironsworn'), 'ironsworn')

function overlap(a: LayoutRect, b: LayoutRect): boolean {
  return a.x < b.x + b.width && b.x < a.x + a.width && a.y < b.y + b.height && b.y < a.y + a.height
}

function inside(inner: LayoutRect, outer: LayoutRect): boolean {
  return (
    inner.x >= outer.x &&
    inner.y >= outer.y &&
    inner.x + inner.width <= outer.x + outer.width &&
    inner.y + inner.height <= outer.y + outer.height
  )
}

type Json = Record<string, unknown>

function defined<T>(value: T | undefined): T {
  if (value === undefined) {
    throw new Error('expected a value')
  }

  return value
}

function build(parts: Parameters<typeof release>[0]): FlowDiagram {
  return flowDiagramFromReleaseContent(release(parts), 'f')
}

describe('layoutFlowDiagram', () => {
  it('is deterministic', () => {
    expect(layoutFlowDiagram(coldBerth)).toEqual(layoutFlowDiagram(coldBerth))
  })

  it('stacks the phases in order, one width, without overlap', () => {
    const { phases, width, height } = layoutFlowDiagram(coldBerth)
    expect(phases.map((item) => item.key)).toEqual(coldBerth.phases.map((item) => item.key))
    phases.forEach((item, index) => {
      expect(item.rect.width).toBe(phases[0]?.rect.width)
      expect(item.rect.x + item.rect.width).toBeLessThanOrEqual(width)
      expect(item.rect.y + item.rect.height).toBeLessThanOrEqual(height)
      const next = phases[index + 1]
      if (next !== undefined) {
        expect(next.rect.y).toBeGreaterThan(item.rect.y + item.rect.height)
      }
    })
  })

  it('keeps every Scene Type inside its phase without overlap, and flags the reached ones', () => {
    const diagram = build({
      sceneTypes: [sceneType('a'), sceneType('b'), sceneType('c')],
      phases: [
        phase('p', {
          selection: { rule: 'sequence', sceneTypes: ['a', 'b'] },
          sceneClosing: [step('s', { effects: [{ kind: 'nextScene', sceneType: 'c' }] })],
        }),
      ],
    })
    const layout = layoutFlowDiagram(diagram)
    const box = defined(layout.phases[0]).rect
    const scenes = layout.nodes.filter((node) => node.kind === 'sceneType')
    expect(scenes.map((node) => [node.id, node.reached])).toEqual([
      ['scene:p:a', false],
      ['scene:p:b', false],
      ['scene:p:c', true],
    ])
    for (const node of layout.nodes) {
      if (node.phase === 'p') {
        expect(inside(node.rect, box)).toBe(true)
      }
    }
    scenes.forEach((node, index) => {
      scenes.slice(index + 1).forEach((other) => {
        expect(overlap(node.rect, other.rect)).toBe(false)
      })
    })
  })

  it('chains a sequence, fans out a player choice and draws an oracle from its table node', () => {
    const selectionEdges = (diagram: FlowDiagram): string[] =>
      layoutFlowDiagram(diagram)
        .edges.filter((edge) => edge.kind === 'selection')
        .map((edge) => edge.id)
    const sceneTypes = [sceneType('a'), sceneType('b'), sceneType('c')]
    const select = (selection: Json, tables: Json[] = []): FlowDiagram =>
      build({ sceneTypes, tables, phases: [phase('p', { selection })] })

    expect(selectionEdges(select({ rule: 'sequence', sceneTypes: ['a', 'b', 'c'] }))).toHaveLength(
      2,
    )
    const player = layoutFlowDiagram(select({ rule: 'player', sceneTypes: ['a', 'b', 'c'] }))
    expect(player.nodes.map((node) => node.kind)).toContain('choice')
    expect(player.edges.filter((edge) => edge.kind === 'selection')).toHaveLength(3)
    const oracle = layoutFlowDiagram(
      select({ rule: 'oracle', table: 'deck' }, [
        table('deck', [
          { text: 'A', sceneType: 'a' },
          { text: 'B', sceneType: 'b' },
        ]),
      ]),
    )
    expect(oracle.nodes.map((node) => node.id)).toContain('table:p')
    expect(oracle.edges.filter((edge) => edge.kind === 'selection')).toHaveLength(2)
  })

  it('draws every transition as exactly one edge', () => {
    for (const diagram of [coldBerth, longCarry, ironsworn]) {
      const ids = layoutFlowDiagram(diagram)
        .edges.filter((edge) => edge.id.startsWith('transition:'))
        .map((edge) => edge.id)
      expect(ids).toEqual(diagram.transitions.map((_, index) => `transition:${String(index)}`))
    }
  })

  it('draws the edges that come through a table dashed, labelled with the table', () => {
    const { edges } = layoutFlowDiagram(coldBerth)
    const next = edges.filter((edge) => edge.kind === 'nextScene')
    expect(next).toHaveLength(4)
    for (const edge of next) {
      expect(edge.dashed).toBe(true)
      expect(edge.label?.text).toBe('Ship trouble')
    }
    const end = edges.find((edge) => edge.kind === 'endPhase')
    expect(end?.dashed).toBe(false)
    expect(end?.label).toBeUndefined()
  })

  it('starts an edge from a hook at its chip', () => {
    const layout = layoutFlowDiagram(coldBerth)
    const chip = layout.phases
      .flatMap((item) => item.header)
      .find((item) => item.id === 'hook:the-ship:worldTurn')
    const edge = layout.edges.find((candidate) => candidate.kind === 'nextScene')
    expect(chip?.kind).toBe('hook')
    expect(chip?.count).toBe(4)
    expect(edge?.points[0]).toEqual({
      x: (chip?.rect.x ?? 0) + (chip?.rect.width ?? 0) / 2,
      y: (chip?.rect.y ?? 0) + (chip?.rect.height ?? 0),
    })
  })

  it('draws a transition from a Scene Type to itself as a loop on top of the node', () => {
    const diagram = build({
      sceneTypes: [
        sceneType('a', {
          play: [step('s', { effects: [{ kind: 'switchSceneType', sceneType: 'a' }] })],
        }),
      ],
      phases: [phase('p', { selection: { rule: 'sequence', sceneTypes: ['a'] } })],
    })
    const layout = layoutFlowDiagram(diagram)
    const node = defined(layout.nodes.find((candidate) => candidate.id === 'scene:p:a'))
    const edge = layout.edges.find((candidate) => candidate.id === 'transition:0')
    const points = edge?.points ?? []
    expect(points.length).toBeGreaterThanOrEqual(4)
    expect(points[0]?.y).toBe(node.rect.y)
    expect(points.at(-1)?.y).toBe(node.rect.y)
    expect(Math.min(...points.map((point) => point.y))).toBeLessThan(node.rect.y)
    expect(inside(node.rect, defined(layout.phases[0]).rect)).toBe(true)
    expect(Math.min(...points.map((point) => point.y))).toBeGreaterThanOrEqual(
      layout.phases[0]?.rect.y ?? 0,
    )
  })

  it('ends a phase at its exit port, connects the phases and closes the flow with an end marker', () => {
    const layout = layoutFlowDiagram(coldBerth)
    const connectors = layout.edges.filter((edge) => edge.kind === 'phase')
    expect(connectors).toHaveLength(coldBerth.phases.length)
    const last = defined(layout.phases.at(-1)).rect
    const end = layout.nodes.find((node) => node.kind === 'end')
    expect(end?.label).toBe('End of flow')
    expect(end?.rect.y).toBeGreaterThan(last.y + last.height)
    expect(layout.height).toBeGreaterThanOrEqual((end?.rect.y ?? 0) + (end?.rect.height ?? 0))
    const ship = defined(layout.phases.find((item) => item.key === 'the-ship')).rect
    const endPhase = layout.edges.find((edge) => edge.kind === 'endPhase')
    expect(endPhase?.points.at(-1)).toEqual({ x: ship.x + ship.width / 2, y: ship.y + ship.height })
  })

  it('ends a one-phase cut in an exit stub naming the next phase', () => {
    const layout = layoutFlowDiagram(phaseDiagram(coldBerth, 'the-ship'))
    expect(layout.nodes.filter((node) => node.kind === 'end')).toEqual([])
    const stub = layout.nodes.find((node) => node.kind === 'exitStub')
    expect(stub?.id).toBe('exit:reveal')
    expect(stub?.label).toBe('Reveal')
    const box = defined(layout.phases[0]).rect
    expect(stub?.rect.y).toBeGreaterThan(box.y + box.height)
  })

  it('fails closed on a transition it cannot place', () => {
    const broken: FlowDiagram = {
      ...coldBerth,
      transitions: [
        {
          kind: 'nextScene',
          phase: 'the-ship',
          from: { type: 'sceneType', sceneType: 'ghost' },
          to: { type: 'sceneType', sceneType: 'repair' },
          via: null,
        },
      ],
    }
    expect(() => layoutFlowDiagram(broken)).toThrow(/transitions\[0\]/)
    const orphan: FlowDiagram = {
      ...coldBerth,
      transitions: [{ ...defined(coldBerth.transitions[0]), phase: 'nowhere' }],
    }
    expect(() => layoutFlowDiagram(orphan)).toThrow(/nowhere/)
  })

  it('lays out the presets without throwing', () => {
    for (const diagram of [coldBerth, longCarry, ironsworn, phaseDiagram(longCarry, 'the-road')]) {
      const layout = layoutFlowDiagram(diagram)
      expect(layout.width).toBeGreaterThan(0)
      expect(layout.height).toBeGreaterThan(0)
    }
  })
})
