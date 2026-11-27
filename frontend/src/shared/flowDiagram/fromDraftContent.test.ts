import { FlowDiagramError } from './flowDiagram'
import { flowDiagramFromDraftContent, flowDiagramFromReleaseContent } from './fromReleaseContent'
import { phase, release, sceneType, step, table } from './testSupport'

const dangling = release({
  sceneTypes: [
    sceneType('a', { play: [step('s', { effects: [{ kind: 'nextScene', sceneType: 'gone' }] })] }),
  ],
  phases: [phase('p', { selection: { rule: 'sequence', sceneTypes: ['a', 'lost'] } })],
})

describe('flowDiagramFromDraftContent', () => {
  it('reports what the release adapter refuses, by node, and draws the rest', () => {
    expect(() => flowDiagramFromReleaseContent(dangling, 'f')).toThrow(FlowDiagramError)

    const { diagram, issues } = flowDiagramFromDraftContent(dangling, 'f')

    expect(diagram.phases[0]?.selection.sceneTypes.map(({ key }) => key)).toEqual(['a'])
    expect(issues).toEqual({
      'phase:p': ['Scene Type "lost" is not in the draft'],
      'scene:p:a': ['Scene Type "gone" is not in the draft'],
    })
  })

  it('says "the release" in the strict error and "the draft" in the lenient issue', () => {
    expect(() => flowDiagramFromReleaseContent(dangling, 'f')).toThrow(/is not in the release/)
  })

  it('skips a Scene Type and a table of a shape it cannot read, and reports where they are needed', () => {
    const content = release({
      sceneTypes: [{ key: 'a', name: 'A', play: 'not a list' }, 42],
      tables: [{ key: 't', name: 'T', entries: 'nope' }],
      phases: [
        phase('p', { selection: { rule: 'sequence', sceneTypes: ['a'] } }),
        phase('q', { selection: { rule: 'oracle', table: 't' } }),
      ],
    })

    const { diagram, issues } = flowDiagramFromDraftContent(content, 'f')

    expect(diagram.phases.map(({ key }) => key)).toEqual(['p', 'q'])
    expect(issues).toEqual({
      sceneTypes: ['must be an object'],
      'scene:p:a': ['must be a list'],
      'table:q': ['must be a list'],
    })
  })

  it('reports an unreadable catalog entry instead of throwing', () => {
    const { issues } = flowDiagramFromDraftContent(
      release({
        sceneTypes: [{ name: 'No key' }],
        tables: [table('t', [])],
        phases: [phase('p')],
      }),
      'f',
    )

    expect(issues).toEqual({ sceneTypes: ['must be a non-empty string'] })
  })

  it('still throws when the content has no such flow', () => {
    expect(() => flowDiagramFromDraftContent(release({ phases: [] }), 'other')).toThrow(
      FlowDiagramError,
    )
  })
})
