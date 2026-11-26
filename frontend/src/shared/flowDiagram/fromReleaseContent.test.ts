import { describe, expect, it } from 'vitest'

import { FlowDiagramError } from './flowDiagram'
import { flowDiagramFromReleaseContent } from './fromReleaseContent'
import { loadPreset, phase, release, sceneType, step, table } from './testSupport'

const keys = (items: { key: string }[]): string[] => items.map((item) => item.key)

describe('Cold Berth', () => {
  const diagram = flowDiagramFromReleaseContent(loadPreset('dogwatch'), 'cold-berth')
  const ship = diagram.phases.find((candidate) => candidate.key === 'the-ship')

  it('names the flow and keeps the phases in order with their modes', () => {
    expect(diagram.name).toBe('Cold Berth')
    expect(diagram.phases.map((item) => [item.key, item.mode])).toEqual([
      ['session-zero', 'once'],
      ['wake', 'once'],
      ['the-ship', 'loop'],
      ['reveal', 'once'],
      ['epilogue', 'once'],
    ])
  })

  it('reads the selections', () => {
    expect(ship?.selection.rule).toBe('player')
    expect(keys(ship?.selection.sceneTypes ?? [])).toEqual([
      'investigate',
      'repair',
      'confront',
      'hide',
    ])
    expect(diagram.phases[0]?.selection.rule).toBe('sequence')
    expect(ship?.reached).toEqual([])
  })

  it('lists only the hooks that have steps, with their counts, in hook order', () => {
    expect(ship?.hooks).toEqual([
      { hook: 'sceneClosing', label: 'Scene closing', steps: 2 },
      { hook: 'worldTurn', label: 'World turn', steps: 4 },
    ])
    expect(diagram.phases[0]?.hooks).toEqual([])
  })

  it('draws the four next-scene transitions from the world turn, via the table they come from', () => {
    const next = diagram.transitions.filter((transition) => transition.kind === 'nextScene')
    expect(next).toEqual(
      ['repair', 'hide', 'investigate', 'confront'].map((target) => ({
        kind: 'nextScene',
        phase: 'the-ship',
        from: { type: 'hook', hook: 'worldTurn' },
        to: { type: 'sceneType', sceneType: target },
        via: { key: 'ship-trouble', name: 'Ship trouble' },
      })),
    )
  })

  it('draws the end of the phase once, from the scene closing to the next phase', () => {
    // Two bands carry an endPhase effect; they are the same transition.
    expect(diagram.transitions.filter((transition) => transition.kind === 'endPhase')).toEqual([
      {
        kind: 'endPhase',
        phase: 'the-ship',
        from: { type: 'hook', hook: 'sceneClosing' },
        to: { type: 'nextPhase', phase: 'reveal', name: 'Reveal' },
        via: null,
      },
    ])
  })

  it('has no other transitions and is deterministic', () => {
    expect(diagram.transitions).toHaveLength(5)
    expect(flowDiagramFromReleaseContent(loadPreset('dogwatch'), 'cold-berth')).toEqual(diagram)
  })
})

describe('The Long Carry', () => {
  const diagram = flowDiagramFromReleaseContent(loadPreset('hard-country'), 'the-long-carry')
  const road = diagram.phases.find((candidate) => candidate.key === 'the-road')

  it('reads the oracle selection with the Scene Types its entries draw', () => {
    expect(road?.mode).toBe('loop')
    expect(road?.selection).toMatchObject({
      rule: 'oracle',
      table: { key: 'road-deck', name: 'The road deck' },
    })
    expect(keys(road?.selection.sceneTypes ?? [])).toEqual([
      'camp',
      'ford',
      'stranger',
      'ruin',
      'omen',
    ])
  })

  it('reads the hooks of the road', () => {
    expect(road?.hooks).toEqual([
      { hook: 'sceneClosing', label: 'Scene closing', steps: 2 },
      { hook: 'worldTurn', label: 'World turn', steps: 2 },
    ])
  })

  it('draws the next scenes of the road risks and the end of the road', () => {
    expect(
      diagram.transitions.filter((t) => t.kind === 'nextScene').map((t) => [t.to, t.via?.key]),
    ).toEqual(
      ['ford', 'stranger', 'ruin', 'omen'].map((target) => [
        { type: 'sceneType', sceneType: target },
        'road-risks',
      ]),
    )
    expect(diagram.transitions.filter((t) => t.kind === 'endPhase').map((t) => t.to)).toEqual([
      { type: 'nextPhase', phase: 'shrine-gate', name: 'The Shrine Gate' },
    ])
  })
})

describe('Ironsworn', () => {
  it('builds and has scene-to-scene transitions', () => {
    const diagram = flowDiagramFromReleaseContent(loadPreset('ironsworn'), 'ironsworn')
    const scenes = diagram.transitions.filter(
      (t) => t.from.type === 'sceneType' && t.to.type === 'sceneType',
    )
    expect(scenes.length).toBeGreaterThan(10)
    expect(diagram.phases.length).toBeGreaterThan(1)
  })
})

describe('every preset', () => {
  it.each(['dogwatch', 'hard-country', 'ironsworn', 'west-marches'])(
    '%s builds every flow',
    (name) => {
      const content = loadPreset(name) as { flows: { key: string }[] }
      for (const flow of content.flows) {
        expect(() => flowDiagramFromReleaseContent(content, flow.key)).not.toThrow()
      }
    },
  )
})

describe('transitions', () => {
  it('draws a switchSceneType from a step band of a Scene Type', () => {
    const content = release({
      sceneTypes: [
        sceneType('a', {
          play: [
            step('check', {
              kind: 'roll',
              dice: '1d6',
              bands: [
                { upTo: 3 },
                { label: 'Switch', effects: [{ kind: 'switchSceneType', sceneType: 'b' }] },
              ],
            }),
          ],
        }),
        sceneType('b'),
      ],
      phases: [phase('p', { selection: { rule: 'player', sceneTypes: ['a', 'b'] } })],
    })
    expect(flowDiagramFromReleaseContent(content, 'f').transitions).toEqual([
      {
        kind: 'switchSceneType',
        phase: 'p',
        from: { type: 'sceneType', sceneType: 'a' },
        to: { type: 'sceneType', sceneType: 'b' },
        via: null,
      },
    ])
  })

  it('finds effects on steps, branches, options, otherwise and every Scene Type part', () => {
    const content = release({
      sceneTypes: [
        sceneType('a', {
          setup: [step('s', { effects: [{ kind: 'nextScene', sceneType: 'b' }] })],
          play: [
            step('p', {
              kind: 'choice',
              options: [{ label: 'x', effects: [{ kind: 'nextScene', sceneType: 'c' }] }],
              branches: { yes: { effects: [{ kind: 'nextScene', sceneType: 'd' }] } },
              otherwise: { effects: [{ kind: 'nextScene', sceneType: 'e' }] },
            }),
          ],
          closing: [step('c', { effects: [{ kind: 'nextScene', sceneType: 'f' }] })],
        }),
        ...['b', 'c', 'd', 'e', 'f'].map((key) => sceneType(key)),
      ],
      phases: [phase('p', { selection: { rule: 'sequence', sceneTypes: ['a'] } })],
    })
    const diagram = flowDiagramFromReleaseContent(content, 'f')
    expect(
      diagram.transitions.map((t) => (t.to.type === 'sceneType' ? t.to.sceneType : '')),
    ).toEqual(['b', 'c', 'd', 'e', 'f'])
  })

  it('ignores effects that are not transitions', () => {
    const content = release({
      sceneTypes: [
        sceneType('a', {
          play: [
            step('t', {
              effects: [
                { kind: 'tracker', tracker: 'x', op: 'add', value: 1 },
                { kind: 'sceneTitle' },
              ],
            }),
          ],
        }),
      ],
      phases: [phase('p', { selection: { rule: 'sequence', sceneTypes: ['a'] } })],
    })
    expect(flowDiagramFromReleaseContent(content, 'f').transitions).toEqual([])
  })

  it('draws a target outside the selection as reached, and what that Scene Type reaches in turn', () => {
    const content = release({
      sceneTypes: [
        sceneType('a', { play: [step('s', { effects: [{ kind: 'nextScene', sceneType: 'b' }] })] }),
        sceneType('b', {
          play: [step('s', { effects: [{ kind: 'switchSceneType', sceneType: 'c' }] })],
        }),
        sceneType('c', { play: [step('s', { effects: [{ kind: 'nextScene', sceneType: 'a' }] })] }),
      ],
      phases: [phase('p', { selection: { rule: 'sequence', sceneTypes: ['a'] } })],
    })
    const diagram = flowDiagramFromReleaseContent(content, 'f')
    expect(diagram.phases[0]?.reached).toEqual([
      { key: 'b', name: 'B' },
      { key: 'c', name: 'C' },
    ])
    expect(diagram.transitions.map((t) => t.kind)).toEqual([
      'nextScene',
      'switchSceneType',
      'nextScene',
    ])
  })

  it('reaches a Scene Type from a hook and from a rolled table', () => {
    const content = release({
      sceneTypes: [sceneType('a'), sceneType('b'), sceneType('c')],
      tables: [table('t', [{ text: 'go', effects: [{ kind: 'nextScene', sceneType: 'c' }] }])],
      phases: [
        phase('p', {
          selection: { rule: 'sequence', sceneTypes: ['a'] },
          sceneOpening: [step('o', { effects: [{ kind: 'nextScene', sceneType: 'b' }] })],
          worldTurn: [step('r', { kind: 'table', table: 't' })],
        }),
      ],
    })
    const diagram = flowDiagramFromReleaseContent(content, 'f')
    expect(keys(diagram.phases[0]?.reached ?? [])).toEqual(['b', 'c'])
    expect(diagram.transitions.map((t) => [t.from, t.via?.key ?? null])).toEqual([
      [{ type: 'hook', hook: 'sceneOpening' }, null],
      [{ type: 'hook', hook: 'worldTurn' }, 't'],
    ])
  })

  it('follows the tables of a step by Scene Type and nested tables', () => {
    const content = release({
      sceneTypes: [sceneType('a'), sceneType('b')],
      tables: [
        table('outer', [{ text: 'again', table: 'inner' }]),
        table('inner', [{ text: 'go', effects: [{ kind: 'nextScene', sceneType: 'b' }] }]),
        table('loop', [{ text: 'self', table: 'loop' }]),
      ],
      phases: [
        phase('p', {
          selection: { rule: 'sequence', sceneTypes: ['a'] },
          worldTurn: [
            step('r', { kind: 'table', table: 'loop', tableBySceneType: { a: 'outer' } }),
          ],
        }),
      ],
    })
    const [transition] = flowDiagramFromReleaseContent(content, 'f').transitions
    expect(transition?.via).toEqual({ key: 'inner', name: 'INNER' })
  })

  it('draws the effects of an oracle selection table from the table', () => {
    const content = release({
      sceneTypes: [sceneType('a'), sceneType('b'), sceneType('c')],
      tables: [
        table('deck', [
          { text: 'A', sceneType: 'a' },
          { text: 'B', sceneType: 'b', effects: [{ kind: 'nextScene', sceneType: 'c' }] },
          { text: 'A again', sceneType: 'a' },
        ]),
      ],
      phases: [phase('p', { mode: 'loop', selection: { rule: 'oracle', table: 'deck' } })],
    })
    const diagram = flowDiagramFromReleaseContent(content, 'f')
    expect(keys(diagram.phases[0]?.selection.sceneTypes ?? [])).toEqual(['a', 'b'])
    expect(keys(diagram.phases[0]?.reached ?? [])).toEqual(['c'])
    expect(diagram.transitions).toEqual([
      {
        kind: 'nextScene',
        phase: 'p',
        from: { type: 'selectionTable' },
        to: { type: 'sceneType', sceneType: 'c' },
        via: null,
      },
    ])
  })

  it('ends the flow when the last phase ends', () => {
    const content = release({
      sceneTypes: [sceneType('a', { closing: [step('end', { effects: [{ kind: 'endPhase' }] })] })],
      phases: [
        phase('one', { selection: { rule: 'sequence', sceneTypes: ['a'] } }),
        phase('two', { selection: { rule: 'sequence', sceneTypes: ['a'] } }),
      ],
    })
    expect(
      flowDiagramFromReleaseContent(content, 'f').transitions.map((t) => [t.phase, t.to]),
    ).toEqual([
      ['one', { type: 'nextPhase', phase: 'two', name: 'TWO' }],
      ['two', { type: 'end' }],
    ])
  })

  it('keeps the act of a phase', () => {
    const content = release({
      sceneTypes: [sceneType('a')],
      phases: [
        phase('one', { act: 'Act One', selection: { rule: 'sequence', sceneTypes: ['a'] } }),
      ],
    })
    expect(flowDiagramFromReleaseContent(content, 'f').phases[0]?.act).toBe('Act One')
  })
})

describe('malformed content', () => {
  const build =
    (content: unknown, flow = 'f'): (() => unknown) =>
    () =>
      flowDiagramFromReleaseContent(content, flow)
  const valid = (overrides: Record<string, unknown> = {}): unknown =>
    release({
      sceneTypes: [sceneType('a')],
      phases: [phase('p', { selection: { rule: 'sequence', sceneTypes: ['a'] }, ...overrides })],
    })

  it('throws a FlowDiagramError naming the path', () => {
    expect(build(null)).toThrow(FlowDiagramError)
    expect(build(null)).toThrow(/^content:/)
  })

  it.each([null, 'text', [], 3])('rejects content that is not an object (%j)', (content) => {
    expect(build(content)).toThrow(FlowDiagramError)
  })

  it('rejects an unknown flow key', () => {
    expect(build(valid(), 'nope')).toThrow(/flows: no flow "nope"/)
  })

  it('rejects a phase without key, name or a valid mode', () => {
    expect(build(valid({ key: undefined }))).toThrow(/flows\[f\]\.phases\[0\]\.key/)
    expect(build(valid({ name: '' }))).toThrow(/flows\[f\]\.phases\[0\]\.name/)
    expect(build(valid({ mode: undefined }))).toThrow(/flows\[f\]\.phases\[0\]\.mode/)
    expect(build(valid({ mode: 'sometimes' }))).toThrow(/flows\[f\]\.phases\[0\]\.mode/)
  })

  it('rejects an unknown or missing selection rule', () => {
    expect(build(valid({ selection: { rule: 'dice', sceneTypes: [] } }))).toThrow(
      /flows\[f\]\.phases\[0\]\.selection\.rule/,
    )
    expect(build(valid({ selection: {} }))).toThrow(/selection\.rule/)
    expect(build(valid({ selection: undefined }))).toThrow(/phases\[0\]\.selection/)
  })

  it('rejects a selection naming an unknown Scene Type or table', () => {
    expect(build(valid({ selection: { rule: 'player', sceneTypes: ['a', 'ghost'] } }))).toThrow(
      /selection\.sceneTypes\[1\].*"ghost"/,
    )
    expect(build(valid({ selection: { rule: 'oracle', table: 'ghost' } }))).toThrow(
      /selection\.table.*"ghost"/,
    )
  })

  it('rejects an entry of an oracle selection table without a Scene Type', () => {
    const content = release({
      sceneTypes: [sceneType('a')],
      tables: [table('deck', [{ text: 'no scene' }])],
      phases: [phase('p', { selection: { rule: 'oracle', table: 'deck' } })],
    })
    expect(build(content)).toThrow(/oracles\.tables\[0\]\.entries\[0\]\.sceneType/)
  })

  it('rejects an effect naming an unknown Scene Type', () => {
    const content = release({
      sceneTypes: [
        sceneType('a', {
          play: [step('s', { effects: [{ kind: 'nextScene', sceneType: 'ghost' }] })],
        }),
      ],
      phases: [phase('p', { selection: { rule: 'sequence', sceneTypes: ['a'] } })],
    })
    expect(build(content)).toThrow(/sceneTypes\[0\]\.play\[0\]\.effects\[0\]\.sceneType.*"ghost"/)
  })

  it('rejects an effect on a table entry naming an unknown Scene Type', () => {
    const content = release({
      sceneTypes: [sceneType('a')],
      tables: [table('t', [{ text: 'x', effects: [{ kind: 'nextScene', sceneType: 'ghost' }] }])],
      phases: [
        phase('p', {
          selection: { rule: 'sequence', sceneTypes: ['a'] },
          worldTurn: [step('r', { kind: 'table', table: 't' })],
        }),
      ],
    })
    expect(build(content)).toThrow(/oracles\.tables\[0\]\.entries\[0\]\.effects\[0\]\.sceneType/)
  })

  it('rejects a step rolling an unknown table', () => {
    const content = release({
      sceneTypes: [sceneType('a')],
      phases: [
        phase('p', {
          selection: { rule: 'sequence', sceneTypes: ['a'] },
          worldTurn: [step('r', { kind: 'table', table: 'ghost' })],
        }),
      ],
    })
    expect(build(content)).toThrow(/phases\[0\]\.worldTurn\[0\]\.table.*"ghost"/)
  })

  it('rejects a step list or step that is not what the contract says', () => {
    expect(build(valid({ worldTurn: 'steps' }))).toThrow(/phases\[0\]\.worldTurn/)
    expect(build(valid({ worldTurn: ['step'] }))).toThrow(/phases\[0\]\.worldTurn\[0\]/)
  })

  it('does not throw when optional fields are absent', () => {
    const content = { flows: [{ key: 'f', name: 'F', phases: [phase('p')] }] }
    expect(build(content)).not.toThrow()
  })
})
