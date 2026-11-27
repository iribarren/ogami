import { readdirSync } from 'node:fs'

import { flowDiagramFromReleaseContent, layoutFlowDiagram } from '@/shared/flowDiagram'
import { loadPreset, phase, release, sceneType } from '@/shared/flowDiagram/testSupport'

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

  it.each(['a4', 'a5'] as const)(
    'never draws a phase larger than the whole diagram would be on %s',
    (paper) => {
      // A tall diagram whose last phase is a small one: cut out, it is narrower than the whole.
      const small = phase('small', { selection: { rule: 'sequence', sceneTypes: ['a'] } })
      const tall = flowDiagramFromReleaseContent(
        release({ sceneTypes: scenes, phases: [...phases(12), small] }),
        'f',
      )
      const layout = layoutFlowDiagram(tall)
      const wholeScale = printableArea(paper).width / (layout.width * MM)

      const pages = diagramPages(tall, paper)
      expect(pages).toHaveLength(13)
      for (const page of pages) {
        const cut = layoutFlowDiagram(page.diagram)
        expect(page.width / (cut.width * MM)).toBeLessThanOrEqual(wholeScale + 1e-9)
      }
    },
  )

  it('pages the diagram of every flow of every preset, each page within the printable area', () => {
    const dir = new URL('../../../../backend/presets/', `file://${import.meta.dirname}/`)
    const names = readdirSync(dir).filter((file) => file.endsWith('.json'))

    expect(names.length).toBeGreaterThan(0)
    for (const file of names) {
      const content = loadPreset(file.replace(/\.json$/, ''))
      // Schema version 1 has no flows.
      const flows = (content as { flows?: { key: string }[] }).flows ?? []
      for (const flow of flows) {
        for (const paper of ['a4', 'a5'] as const) {
          const pages = diagramPages(flowDiagramFromReleaseContent(content, flow.key), paper)
          const area = printableArea(paper)

          expect(pages.length, `${file} ${flow.key}`).toBeGreaterThan(0)
          for (const page of pages) {
            expect(page.width).toBeLessThanOrEqual(area.width + 1e-9)
            expect(page.phase === null || page.height <= area.height + 1e-9).toBe(true)
          }
        }
      }
    }
  })
})
