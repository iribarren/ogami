import { narrative } from '../../fixtures'
import { stepContext } from './stepContext'

describe('the step pickers', () => {
  it('read Scene Types, fact slots and sheet fields from the narrative, falling back to the key', () => {
    const context = stepContext({
      ...narrative,
      sceneTypes: [
        { key: 'chase', definition: { key: 'chase', name: 'Chase' }, usedBy: [] },
        { key: 'plain', definition: { key: 'plain' }, usedBy: [] },
      ],
      factSlots: [
        ...narrative.factSlots,
        { key: 'odd', definition: { key: 'odd', label: 5 }, usedBy: [] },
      ],
      catalog: {
        ...narrative.catalog,
        tables: [{ key: 'moods', name: 'Moods', entries: ['calm', 'tense'] }],
        likelihood: [{ key: 'fate', name: 'Fate', levels: ['unlikely', 'likely'] }],
        checks: [
          {
            key: 'skill',
            label: 'Skill check',
            bands: ['fail', 'success'],
            difficulty: { default: 13, min: 9, max: null },
          },
          { key: 'luck', label: 'Luck', bands: ['win'], difficulty: null },
        ],
        fields: [
          { key: 'hands', label: 'Hands' },
          { key: 'bar', label: 'Bar', options: null },
          {
            key: 'physical',
            label: 'Physical',
            options: [
              { key: 'hurt', label: 'Hurt' },
              { key: 'tired', label: 'Tired' },
            ],
          },
        ],
      },
    })

    expect(context.tables).toEqual([{ key: 'moods', name: 'Moods', entries: ['calm', 'tense'] }])
    expect(context.likelihood).toEqual([
      { key: 'fate', name: 'Fate', levels: ['unlikely', 'likely'] },
    ])
    expect(context.checks).toEqual([
      {
        key: 'skill',
        name: 'Skill check',
        bands: ['fail', 'success'],
        difficulty: { default: 13, min: 9, max: null },
      },
      { key: 'luck', name: 'Luck', bands: ['win'], difficulty: null },
    ])
    expect(context.sceneTypes).toEqual([
      { key: 'chase', name: 'Chase' },
      { key: 'plain', name: 'plain' },
    ])
    expect(context.factSlots).toEqual([
      { key: 'target', name: 'Target', type: 'npc' },
      { key: 'client', name: 'Client', type: 'text' },
      { key: 'odd', name: 'odd', type: 'text' },
    ])
    // A conditions field carries its options for the effect to mark or clear; a number or track has none.
    expect(context.sheetFields).toEqual([
      { key: 'hands', name: 'Hands' },
      { key: 'bar', name: 'Bar' },
      {
        key: 'physical',
        name: 'Physical',
        options: [
          { key: 'hurt', name: 'Hurt' },
          { key: 'tired', name: 'Tired' },
        ],
      },
    ])
    expect(context.trackers.map((tracker) => tracker.key)).toEqual(['heat', 'alarm', 'mood'])
  })
})
