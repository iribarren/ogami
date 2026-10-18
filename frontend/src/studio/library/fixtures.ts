import type { GameSystem } from '../useGameSystems'
import type { LibraryPackCatalog, LibraryPackSummary } from './useLibraryPacks'

// Shared by the library tests and stories.

/** A pack I wrote. */
export const myPack: LibraryPackSummary = {
  key: 'fantasy-basics',
  name: 'Fantasy basics',
  description: 'Names, rumours and a tavern.',
  mine: true,
  itemCounts: { oracleTables: 2, likelihoodOracles: 0, trackers: 1, tags: 3, sceneTypes: 1 },
  updatedAt: '2026-10-16T09:00:00+00:00',
}

/** A pack another game manager wrote, without a description. */
export const otherPack: LibraryPackSummary = {
  key: 'wild-tales',
  name: 'Wild tales',
  description: null,
  mine: false,
  itemCounts: { oracleTables: 1, likelihoodOracles: 1, trackers: 0, tags: 0, sceneTypes: 0 },
  updatedAt: '2026-10-15T09:00:00+00:00',
}

/** A pack with no items yet. */
export const emptyPack: LibraryPackSummary = {
  key: 'blank-pack',
  name: 'Blank pack',
  description: null,
  mine: true,
  itemCounts: {},
  updatedAt: '2026-10-17T09:00:00+00:00',
}

/** `myPack` as the catalog shows it: every section, one Scene Type naming an oracle and a tag. */
export const catalog: LibraryPackCatalog = {
  key: 'fantasy-basics',
  name: 'Fantasy basics',
  description: 'Names, rumours and a tavern.',
  mine: true,
  updatedAt: '2026-10-16T09:00:00+00:00',
  sections: [
    {
      section: 'oracles',
      items: [
        { key: 'names', label: 'Names', kind: 'table', dependsOn: [] },
        { key: 'mood', label: 'Mood', kind: 'likelihood', dependsOn: [] },
      ],
    },
    { section: 'trackers', items: [{ key: 'fame', label: 'Fame', kind: null, dependsOn: [] }] },
    { section: 'tags', items: [{ key: 'social', label: 'Social', kind: null, dependsOn: [] }] },
    { section: 'fact-slots', items: [] },
    {
      section: 'scene-types',
      items: [
        {
          key: 'tavern',
          label: 'Tavern',
          kind: null,
          dependsOn: [
            { section: 'oracles', key: 'names' },
            { section: 'tags', key: 'social' },
          ],
        },
      ],
    },
  ],
}

/** Someone else's pack: read only. */
export const othersCatalog: LibraryPackCatalog = {
  ...catalog,
  key: 'wild-tales',
  name: 'Wild tales',
  description: null,
  mine: false,
}

/** `myPack` as the GameSystem endpoints show it. */
export const packSystem: GameSystem = {
  key: 'fantasy-basics',
  kind: 'library-pack',
  name: 'Fantasy basics',
  description: 'Names, rumours and a tavern.',
  credits: null,
  theme: null,
  schemaVersion: 2,
  sectionCounts: { oracleTables: 2, trackers: 1 },
  latestReleaseVersion: null,
  hasUnpublishedChanges: false,
  libraryChanges: 0,
  createdAt: '2026-10-15T09:00:00+00:00',
  updatedAt: '2026-10-16T09:00:00+00:00',
}
