import { narrative, sheet, sheetWithEconomy } from '../../fixtures'
import { specialsOf, stepContext } from './stepContext'

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

describe('the special results a check can produce', () => {
  const pool = { count: 3, sides: 10, successAt: 6 }

  it.each([
    ['no pool', { dice: '1d6' }, []],
    ['a pool without criticals or Hunger dice', { pool }, []],
    ['null criticals and Hunger dice', { pool: { ...pool, criticals: null, hunger: null } }, []],
    ['critical pairs', { pool: { ...pool, criticals: { bonus: 2 } } }, ['critical']],
    ['Hunger dice', { pool: { ...pool, hunger: { count: 1 } } }, ['bestialFailure']],
    [
      'a Hunger formula',
      { pool: { ...pool, hunger: { count: { formula: '{hunger}' } } } },
      ['bestialFailure'],
    ],
    ['a literal Hunger count of 0', { pool: { ...pool, hunger: { count: 0 } } }, []],
    [
      'critical pairs and Hunger dice',
      { pool: { ...pool, criticals: { bonus: 2 }, hunger: { count: 2 } } },
      ['critical', 'messyCritical', 'bestialFailure'],
    ],
    [
      'critical pairs and a literal Hunger count of 0',
      { pool: { ...pool, criticals: { bonus: 2 }, hunger: { count: 0 } } },
      ['critical'],
    ],
  ])('for %s', (_, stored, expected) => {
    expect(specialsOf({ key: 'k', label: 'K', bands: [], ...stored })).toEqual(expected)
  })

  it('go into the picker only for the checks the draft holds', () => {
    const checks = {
      checks: [{ key: 'skill', label: 'Skill', pool: { ...pool, criticals: { bonus: 2 } } }],
    } as unknown as Parameters<typeof stepContext>[1]
    const catalog = {
      ...narrative.catalog,
      checks: [
        { key: 'skill', label: 'Skill', bands: ['fail', 'win'], difficulty: null },
        { key: 'luck', label: 'Luck', bands: ['win'], difficulty: null },
      ],
    }

    const [skill, luck] = stepContext({ ...narrative, catalog }, checks).checks
    expect(skill?.specials).toEqual(['critical'])
    expect(luck).not.toHaveProperty('specials')
    expect(stepContext({ ...narrative, catalog }).checks[0]).not.toHaveProperty('specials')
  })

  it('offer the gear catalog and the currency of the sheet, and nothing before it loads', () => {
    expect(stepContext(narrative).economy).toBeUndefined()
    expect(stepContext(narrative, undefined, sheet).economy).toEqual({ gear: [], currency: false })
    expect(stepContext(narrative, undefined, sheetWithEconomy).economy).toEqual({
      gear: [
        { key: 'used-pistol', name: 'Used pistol', category: 'Weapons' },
        { key: 'medkit', name: 'Medkit', category: 'Medical' },
        { key: 'rope', name: 'Rope', category: null },
      ],
      currency: true,
    })
  })
})
