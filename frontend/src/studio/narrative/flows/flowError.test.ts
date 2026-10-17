import { flowAnswer, flowChecks, noAnswer } from './flowError'
import { flowToDraft } from './flowDraft'

const phase = (key: string) => ({
  key,
  name: key,
  mode: 'once',
  selection: { rule: 'sequence', sceneTypes: ['ambush'] },
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
      expect(answer.phases[2]).toEqual({ fields: { [field]: 'nope' }, messages: [] })
      expect(answer.phases[0]).toBeUndefined()
      expect(answer.page).toBeNull()
    },
  )

  it('lists anything else under a phase, selection and hooks included, on its card', () => {
    expect(
      flowAnswer('flows[0].phases[1].selection.sceneTypes[0]: unknown Scene Type "x"', 0, 2)
        .phases[1],
    ).toEqual({ fields: {}, messages: ['selection.sceneTypes[0]: unknown Scene Type "x"'] })
    expect(
      flowAnswer('flows[0].phases[0].sessionOpening[2].title: must not be blank', 0, 1).phases[0]
        ?.messages,
    ).toEqual(['sessionOpening[2].title: must not be blank'])
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
      phases: [undefined, { fields: { key: 'Enter a key.' }, messages: [] }],
    })
  })

  it('refuses a phase key another phase already has, on the later phase', () => {
    expect(flowChecks(draft('setup', 'job', 'setup'))?.phases).toEqual([
      undefined,
      undefined,
      { fields: { key: 'Another phase has this key.' }, messages: [] },
    ])
  })
})
