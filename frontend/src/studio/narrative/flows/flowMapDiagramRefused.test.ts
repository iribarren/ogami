import { flowToDraft, type FlowDraft } from './flowDraft'
import { flowDiagramFromDraft } from './flowMapDiagram'

// A draft whose hooks are refused even after the refused ones were blanked: only a stubbed
// `flowToItem` can say so, since the real one refuses only hooks it then blanks.
vi.mock('./flowDraft', async (original) => {
  const actual = await original<typeof import('./flowDraft')>()
  return { ...actual, flowToItem: vi.fn(actual.flowToItem) }
})

const draft = (): FlowDraft => {
  const made = flowToDraft({
    key: 'f',
    name: 'F',
    defaultView: 'focus',
    oracles: [],
    trackers: [],
    phases: [
      {
        key: 'one',
        name: 'One',
        mode: 'once',
        selection: { rule: 'sequence', sceneTypes: ['a'] },
        worldTurn: [{ key: 'w', kind: 'prompt', title: 'W', mandatory: true }],
      },
    ],
  })
  if (made === null) {
    throw new Error('Expected a readable flow.')
  }
  return made
}

describe('flowDiagramFromDraft when the second build is refused too', () => {
  it('still draws the phases and puts the reason on the hook chips', async () => {
    const { flowToItem } = await import('./flowDraft')
    const actual = await vi.importActual<typeof import('./flowDraft')>('./flowDraft')
    const refuse = { errors: { 0: { worldTurn: { title: 'Say what it is.' } } } }
    vi.mocked(flowToItem)
      .mockReturnValueOnce(refuse)
      .mockReturnValueOnce(refuse)
      .mockImplementation(actual.flowToItem)

    const { diagram, issues, drawn } = flowDiagramFromDraft(
      draft(),
      [{ definition: { key: 'a', name: 'A', setup: [], play: [], closing: [] } }],
      [],
    )

    expect(diagram.phases.map(({ key }) => key)).toEqual(['one'])
    expect(issues['hook:one:worldTurn']).toEqual(['Say what it is.'])
    expect(drawn.has('hook:one:worldTurn')).toBe(true)
  })

  it('says so above the map when nothing can be built at all', async () => {
    const { flowToItem } = await import('./flowDraft')
    const refuse = { errors: { 0: { worldTurn: { title: 'Say what it is.' } } } }
    vi.mocked(flowToItem).mockReturnValue(refuse)

    const { flowIssues, issues, drawn } = flowDiagramFromDraft(draft(), [], [])

    expect(flowIssues).toEqual([
      'The flow cannot be drawn from this form yet.',
      'hook:one:worldTurn: Say what it is.',
    ])
    expect(Object.keys(issues).filter((id) => !drawn.has(id))).toEqual([])
  })
})
