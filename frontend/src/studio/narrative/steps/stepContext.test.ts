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
        fields: [{ key: 'hands', label: 'Hands' }],
      },
    })

    expect(context.tables).toEqual([{ key: 'moods', name: 'Moods', entries: ['calm', 'tense'] }])
    expect(context.likelihood).toEqual([
      { key: 'fate', name: 'Fate', levels: ['unlikely', 'likely'] },
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
    expect(context.sheetFields).toEqual([{ key: 'hands', name: 'Hands' }])
    expect(context.trackers.map((tracker) => tracker.key)).toEqual(['heat', 'alarm', 'mood'])
  })
})
