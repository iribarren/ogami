import { locateError, splitWarning, type LocatorNames } from './validationLocation'

const item = (key: string, definition: Record<string, unknown> = {}) => ({
  key,
  definition: { key, ...definition },
})

const names: LocatorNames = {
  flows: [
    item('heist', {
      name: 'One-shot',
      phases: [
        { name: 'Setup' },
        { name: 'Climax', phaseClosing: [{ title: 'Did you learn something about the vault?' }] },
      ],
    }),
  ],
  sceneTypes: [
    item('ambush', { name: 'Ambush', setup: [{ title: 'Set the scene' }], play: [], closing: [] }),
  ],
  tables: [item('weather', { name: 'Weather' })],
  likelihood: [item('fate', { name: 'Fate' })],
  trackers: [item('alarm', { name: 'Alarm' })],
  tags: [],
  factSlots: [],
}

describe('where a validation error is', () => {
  it('names a flow step field and links to its node in the flow editor', () => {
    const where = locateError('flows[0].phases[1].phaseClosing[0].skipLabel', names)

    expect(where.group).toBe('Flows · One-shot')
    expect(where.trail).toEqual([
      '2. Climax',
      'Phase closing',
      'Step 1 “Did you learn something…”',
      'Skip label',
    ])
    expect(where.target).toEqual({
      kind: 'flow',
      flow: 'heist',
      node: 'phases.1.hooks.phaseClosing.0',
    })
  })

  it('selects the selection, the phase or the settings of a flow', () => {
    expect(locateError('flows[0].phases[0].selection.table', names).target).toEqual({
      kind: 'flow',
      flow: 'heist',
      node: 'phases.0.selection',
    })
    expect(locateError('flows[0].phases[1].mode', names).target).toEqual({
      kind: 'flow',
      flow: 'heist',
      node: 'phases.1',
    })
    expect(locateError('flows[0].name', names).target).toEqual({ kind: 'flow', flow: 'heist' })
    expect(locateError('flows[0].phases[1].mode', names).trail).toEqual(['2. Climax', 'Mode'])
  })

  it('opens the editor of a Scene Type, an oracle table or a likelihood oracle', () => {
    const scene = locateError('sceneTypes[0].setup[0].title', names)
    expect(scene.group).toBe('Scene Types · Ambush')
    expect(scene.trail).toEqual(['Setup', 'Step 1 “Set the scene”', 'Title'])
    expect(scene.target).toEqual({ kind: 'sceneType', sceneType: 'ambush' })

    expect(locateError('oracles.tables[0].entries[2].text', names)).toMatchObject({
      group: 'Oracle tables · Weather',
      trail: ['Entries 3', 'Text'],
      target: { kind: 'table', table: 'weather' },
    })
    expect(locateError('oracles.likelihood[0].levels[1].key', names)).toMatchObject({
      group: 'Likelihood oracles · Fate',
      target: { kind: 'likelihood', oracle: 'fate' },
    })
  })

  it('places an error about a check set or a table by Scene Type on its step of the Scene Type', () => {
    const play = [
      { title: 'Defy disaster', checks: ['skill', 'finesse'] },
      { title: 'Complication', tableBySceneType: { ambush: 'weather' } },
    ]
    const withPlay = { ...names, sceneTypes: [item('ambush', { name: 'Ambush', play })] }

    expect(locateError('sceneTypes[0].play[0].checks[1]', withPlay)).toMatchObject({
      group: 'Scene Types · Ambush',
      trail: ['Play', 'Step 1 “Defy disaster”', 'Checks 2'],
      target: { kind: 'sceneType', sceneType: 'ambush' },
    })
    expect(locateError('sceneTypes[0].play[1].tableBySceneType.ambush', withPlay).trail).toEqual([
      'Play',
      'Step 2 “Complication”',
      'Table by scene type',
      'Ambush',
    ])
    expect(locateError('sceneTypes[0].play[0].options[1].effects[0]', withPlay).trail).toEqual([
      'Play',
      'Step 1 “Defy disaster”',
      'Options 2',
      'Effects 1',
    ])
  })

  it('links the other sections to their tab', () => {
    const tab = (path: string) => locateError(path, names).target
    expect(tab('trackers[0].max')).toEqual({ kind: 'tab', tab: 'narrative' })
    expect(tab('tags[3].key')).toEqual({ kind: 'tab', tab: 'narrative' })
    expect(tab('factSlots[0].label')).toEqual({ kind: 'tab', tab: 'narrative' })
    expect(tab('sheet.fields[1].key')).toEqual({ kind: 'tab', tab: 'sheet' })
    expect(tab('checks[0].bands[1].upTo')).toEqual({ kind: 'tab', tab: 'checks' })
    expect(tab('library.imports[0]')).toEqual({ kind: 'tab', tab: 'library' })
    expect(tab('oracles')).toEqual({ kind: 'tab', tab: 'oracles' })
    expect(locateError('trackers[0].max', names).group).toBe('Trackers · Alarm')
  })

  it('falls back to the section tab when the item is not in the draft', () => {
    const where = locateError('flows[4].phases[0].mode', names)

    expect(where.group).toBe('Flows · 5')
    expect(where.target).toEqual({ kind: 'tab', tab: 'flows' })
  })

  it('falls back to the Overview and the raw path for what it does not know', () => {
    for (const path of [
      '(root)',
      'schemaVersion',
      'gameSystem.credits.url',
      'theme',
      'mystery[2]',
      '',
    ]) {
      const where = locateError(path, names)
      expect(where.target).toEqual({ kind: 'tab', tab: 'overview' })
      expect(where.group).toBe('Draft')
    }
    expect(locateError('mystery[2]', names).trail).toEqual([])
    expect(locateError('gameSystem.credits.url', names).trail).toEqual(['Credits', 'Url'])
  })
})

describe('splitting a warning', () => {
  it('keeps every word of a pathless warning that contains a colon', () => {
    expect(splitWarning('Note: the vault has no door')).toEqual({
      reason: 'Note: the vault has no door',
    })
    expect(splitWarning('nothing at all')).toEqual({ reason: 'nothing at all' })
  })

  it('treats a path-shaped prefix with an unknown head as part of the reason', () => {
    expect(splitWarning('mystery[1]: has no steps')).toEqual({
      reason: 'mystery[1]: has no steps',
    })
  })

  it('splits a warning that starts with a release path', () => {
    expect(splitWarning('flows[1]: has no steps')).toEqual({
      path: 'flows[1]',
      reason: 'has no steps',
    })
    expect(splitWarning('oracles.tables[0].rows: empty: really')).toEqual({
      path: 'oracles.tables[0].rows',
      reason: 'empty: really',
    })
  })
})
