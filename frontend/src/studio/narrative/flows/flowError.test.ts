import { answerCount, flowAnswer, flowChecks, noAnswer, type PhaseAnswer } from './flowError'
import { flowToDraft, setHook } from './flowDraft'
import { stepsToDrafts } from '../steps/stepDraft'

const phase = (key: string) => ({
  key,
  name: key,
  mode: 'once',
  selection: { rule: 'sequence', sceneTypes: ['ambush'] },
})

/** A phase answer with only what the test says. */
const said = (answer: Partial<PhaseAnswer>): PhaseAnswer => ({
  fields: {},
  messages: [],
  selection: [],
  hooks: {},
  ...answer,
})

const draft = (...keys: string[]) => {
  const read = flowToDraft({
    key: 'heist',
    name: 'Heist',
    defaultView: 'focus',
    oracles: [],
    trackers: [],
    phases: keys.map(phase),
  })
  if (read === null) {
    throw new Error('Unreadable flow.')
  }
  return read
}

describe('the answer to a refused flow', () => {
  it.each(['key', 'name', 'description', 'introduction', 'default', 'defaultView'])(
    'puts a message about the field %s next to it',
    (field) => {
      expect(flowAnswer(`flows[2].${field}: nope`, 2, 1)).toEqual({
        ...noAnswer,
        fields: { [field]: 'nope' },
      })
    },
  )

  it('puts a message about one oracle or tracker on its list', () => {
    expect(flowAnswer('flows[0].trackers[1]: unknown tracker "x"', 0, 1).fields).toEqual({
      trackers: 'unknown tracker "x"',
    })
  })

  it.each(['key', 'name', 'act', 'description', 'mode'])(
    'puts a message about the phase field %s on its card',
    (field) => {
      const answer = flowAnswer(`flows[1].phases[2].${field}: nope`, 1, 3)
      expect(answer.phases[2]).toEqual(said({ fields: { [field]: 'nope' } }))
      expect(answer.phases[0]).toBeUndefined()
      expect(answer.page).toBeNull()
    },
  )

  it('puts a message about the selection on its card, without the path', () => {
    for (const text of [
      'flows[0].phases[1].selection.sceneTypes[0]: unknown Scene Type "x"',
      'flows[0].phases[1].selection.sceneTypes: must list at least 1 Scene Types',
      'flows[0].phases[1].selection.table: unknown oracle table "x"',
      'flows[0].phases[1].selection.rule: must be one of sequence, player, oracle',
      'flows[0].phases[1].selection: must be an object',
    ]) {
      expect(flowAnswer(text, 0, 2).phases[1]).toEqual(
        said({ selection: [text.slice(text.indexOf(': ') + 2)] }),
      )
    }
  })

  it('puts a message about a hook step on that hook, mapped like a Scene Type step', () => {
    const answer = flowAnswer('flows[0].phases[1].worldTurn[2].effects[0].value: nope', 0, 2)

    expect(answer.phases[1]).toEqual(
      said({
        hooks: {
          worldTurn: [{ index: 2, band: null, effect: 0, field: 'value', message: 'nope' }],
        },
      }),
    )
    expect(answer.phases[0]).toBeUndefined()
  })

  it('puts a message about the hook itself on its list, path included', () => {
    const text = 'flows[0].phases[0].sceneOpening: at most 50 steps'

    expect(flowAnswer(text, 0, 1).phases[0]).toEqual(
      said({
        hooks: {
          sceneOpening: [{ index: null, band: null, effect: null, field: null, message: text }],
        },
      }),
    )
  })

  it('does not take another name for a hook, and keeps a path it cannot read on the card, whole', () => {
    expect(flowAnswer('flows[0].phases[0].worldTurnX[0].title: nope', 0, 1).phases[0]).toEqual(
      said({ messages: ['worldTurnX[0].title: nope'] }),
    )
    expect(flowAnswer('flows[0].phases[0].worldTurn[0]weird: nope', 0, 1).phases[0]).toEqual(
      said({ messages: ['flows[0].phases[0].worldTurn[0]weird: nope'] }),
    )
  })

  it('lists anything else under a phase on its card', () => {
    expect(flowAnswer('flows[0].phases[0]: odd', 0, 1).phases[0]?.messages).toEqual(['odd'])
  })

  it('keeps the whole message on the page when it is about another flow, no phase of this one or the phase list', () => {
    for (const text of [
      'flows[1].name: nope',
      'flows[0].phases[5].key: nope',
      'flows[0].phases: at most 20 phases',
      'flows[0]: odd',
      'trackers[0].key: nope',
    ]) {
      expect(flowAnswer(text, 0, 2)).toEqual({ ...noAnswer, page: text })
    }
  })
})

describe('the checks made before a save', () => {
  it('finds nothing wrong with a flow whose keys are set and distinct', () => {
    expect(flowChecks(draft('setup', 'job'))).toBeNull()
  })

  it('refuses an empty flow key and an empty phase key', () => {
    const empty = { ...draft('setup', ''), key: ' ' }

    expect(flowChecks(empty)).toEqual({
      ...noAnswer,
      fields: { key: 'Enter a key.' },
      phases: [undefined, said({ fields: { key: 'Enter a key.' } })],
    })
  })

  it('refuses a phase key another phase already has, on the later phase', () => {
    expect(flowChecks(draft('setup', 'job', 'setup'))?.phases).toEqual([
      undefined,
      undefined,
      said({ fields: { key: 'Another phase has this key.' } }),
    ])
  })
})

describe('the selection checks made before a save', () => {
  const withSelection = (selection: unknown) => {
    const read = flowToDraft({
      key: 'heist',
      name: 'Heist',
      defaultView: 'focus',
      oracles: [],
      trackers: [],
      phases: [{ ...phase('setup'), selection }],
    })
    if (read === null) {
      throw new Error('Unreadable flow.')
    }
    return read
  }

  it.each([
    [{ rule: 'sequence', sceneTypes: [] }, 'Pick at least one Scene Type.'],
    [{ rule: 'player', sceneTypes: [] }, 'Pick at least one Scene Type.'],
    [{ rule: 'oracle', table: '' }, 'Pick an oracle table.'],
    [
      { rule: 'player', sceneTypes: ['a', 'b', 'a'] },
      'A player selection lists each Scene Type once.',
    ],
    [
      { rule: 'sequence', sceneTypes: Array.from({ length: 21 }, () => 'a') },
      'Pick at most 20 Scene Types.',
    ],
  ])('refuses %j', (selection, message) => {
    expect(flowChecks(withSelection(selection))?.phases[0]).toEqual(said({ selection: [message] }))
  })

  it('accepts a sequence naming a Scene Type twice and an oracle table', () => {
    expect(flowChecks(withSelection({ rule: 'sequence', sceneTypes: ['a', 'a'] }))).toBeNull()
    expect(flowChecks(withSelection({ rule: 'oracle', table: 'places' }))).toBeNull()
  })

  it('carries the hook step errors of the save onto their steps', () => {
    const answer = flowChecks(draft('setup'), {
      0: { worldTurn: { '1.bands.0.upTo': 'Use a whole number.' } },
    })

    expect(answer?.phases[0]?.hooks.worldTurn).toEqual([
      { index: 1, band: 0, effect: null, field: 'upTo', message: 'Use a whole number.' },
    ])
    // A hook with nothing in it is not a problem.
    expect(flowChecks(setHookOn(draft('setup')))).toBeNull()
  })
})

const setHookOn = (flow: ReturnType<typeof draft>) => ({
  ...flow,
  phases: flow.phases.map((phase) => setHook(phase, 'worldTurn', stepsToDrafts([]))),
})

describe('the number of messages in an answer', () => {
  it('is none for a clean answer and one for a message of the page', () => {
    expect(answerCount(noAnswer)).toBe(0)
    expect(answerCount(flowAnswer('flows[1].name: nope', 0, 1))).toBe(1)
  })

  it.each([
    ['flows[0].name: nope', 1],
    ['flows[0].phases[0].key: nope', 1],
    ['flows[0].phases[0].selection.sceneTypes[0]: nope', 1],
    ['flows[0].phases[0].worldTurn: nope', 1],
    ['flows[0].phases[0].worldTurn[2].title: nope', 1],
  ])('counts %s as %i', (message, count) => {
    expect(answerCount(flowAnswer(message, 0, 1))).toBe(count)
  })

  it('adds up fields, phases, selections and hooks of what the checks refuse', () => {
    const flow = flowToDraft({
      key: ' ',
      name: 'Heist',
      defaultView: 'focus',
      oracles: [],
      trackers: [],
      phases: [
        { ...phase(' '), selection: { rule: 'sequence', sceneTypes: [] } },
        { ...phase('b'), worldTurn: [{ key: 's', kind: 'prompt', title: 'S' }] },
      ],
    })
    const checked = flow && flowChecks(flow)

    expect(checked && answerCount(checked)).toBe(3)
  })
})
