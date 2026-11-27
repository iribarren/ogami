import { flowDiagramFromReleaseContent, layoutFlowDiagram } from '@/shared/flowDiagram'
import { phase, release, sceneType } from '@/shared/flowDiagram/testSupport'

import { diagramPages } from './diagramPages'
import { printableArea } from './layout'

const MM = 25.4 / 96

const scenes = ['a', 'b', 'c'].map((key) => sceneType(key))
const phases = (count: number) =>
  Array.from({ length: count }, (_, at) =>
    phase(`p${String(at)}`, { selection: { rule: 'sequence', sceneTypes: ['a', 'b', 'c'] } }),
  )
const diagram = (count: number) =>
  flowDiagramFromReleaseContent(release({ sceneTypes: scenes, phases: phases(count) }), 'f')

describe('diagramPages', () => {
  it('prints no page for a flow without phases', () => {
    expect(diagramPages(diagram(0), 'a4')).toEqual([])
  })

  it.each(['a4', 'a5'] as const)(
    'puts a small diagram whole on one page of %s, scaled to the printable width',
    (paper) => {
      const small = diagram(1)
      const pages = diagramPages(small, paper)

      expect(pages).toHaveLength(1)
      expect(pages[0]?.phase).toBeNull()
      expect(pages[0]?.diagram).toBe(small)
      const area = printableArea(paper)
      expect(pages[0]?.width).toBeCloseTo(area.width)
      expect(pages[0]?.height).toBeLessThanOrEqual(area.height)
      // The proportions of the drawing are kept.
      const layout = layoutFlowDiagram(small)
      expect((pages[0]?.height ?? 0) / (pages[0]?.width ?? 1)).toBeCloseTo(
        layout.height / layout.width,
      )
    },
  )

  it.each(['a4', 'a5'] as const)(
    'cuts a tall diagram into a page for each phase on %s, each scaled to fit',
    (paper) => {
      const tall = diagram(12)
      const area = printableArea(paper)
      // It is a tall one: at the printable width it would not fit the page.
      const layout = layoutFlowDiagram(tall)
      expect(layout.height * MM * (area.width / (layout.width * MM))).toBeGreaterThan(area.height)

      const pages = diagramPages(tall, paper)

      expect(pages.map((page) => page.phase)).toEqual(tall.phases.map((candidate) => candidate.key))
      for (const page of pages) {
        expect(page.diagram.phases).toHaveLength(1)
        expect(page.width).toBeLessThanOrEqual(area.width + 1e-9)
        expect(page.height).toBeLessThanOrEqual(area.height + 1e-9)
      }
    },
  )
})
