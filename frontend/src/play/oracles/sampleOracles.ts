import type { LikelihoodOracleDefinition, OracleTableDefinition } from './useOracles'

// Generic placeholder definitions for the Play screen until GameSystem releases
// provide real ones (feature 4). Not taken from any published oracle.

/** A d100 likelihood oracle with five levels, a 1–9 chaos factor and 20 % exceptional bands. */
export const sampleLikelihoodOracle: LikelihoodOracleDefinition = {
  sides: 100,
  levels: [
    { key: 'very-unlikely', label: 'Very unlikely', target: 15 },
    { key: 'unlikely', label: 'Unlikely', target: 35 },
    { key: 'even', label: 'Even odds', target: 50 },
    { key: 'likely', label: 'Likely', target: 65 },
    { key: 'very-likely', label: 'Very likely', target: 85 },
  ],
  chaos: { min: 1, max: 9, neutral: 5, shiftPerPoint: 5 },
  exceptionalPercent: 20,
}

/** A ranged table that nests a weighted one, plus a weighted and a two-dice ranged table. */
export const sampleOracleTables: OracleTableDefinition[] = [
  {
    key: 'weather',
    name: 'Weather',
    dice: '1d6',
    entries: [
      { min: 1, max: 3, text: 'Clear skies' },
      { min: 4, max: 5, text: 'Rain' },
      { min: 6, max: 6, text: 'A storm', table: 'storm-kind' },
    ],
  },
  {
    key: 'storm-kind',
    name: 'Storm kind',
    entries: [
      { text: 'Thunderstorm', weight: 3 },
      { text: 'Hail', weight: 2 },
      { text: 'Blizzard', weight: 1 },
    ],
  },
  {
    key: 'npc-mood',
    name: 'NPC mood',
    entries: [
      { text: 'Friendly', weight: 2 },
      { text: 'Indifferent', weight: 3 },
      { text: 'Wary', weight: 2 },
      { text: 'Hostile', weight: 1 },
    ],
  },
  {
    key: 'travel-event',
    name: 'Travel event',
    dice: '2d6',
    entries: [
      { min: 2, max: 4, text: 'Trouble on the road' },
      { min: 5, max: 9, text: 'An uneventful day' },
      { min: 10, max: 11, text: 'A fellow traveller' },
      { min: 12, max: 12, text: 'Strange weather', table: 'weather' },
    ],
  },
]
