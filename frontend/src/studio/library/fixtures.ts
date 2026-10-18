import type { GameSystem } from '../useGameSystems'
import type { LibraryImportPreview, LibraryImports } from './useLibraryImports'
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

const fantasyBasics = { key: 'fantasy-basics', name: 'Fantasy basics' }

/** What a draft imported: one of each status, one edited locally, one whose pack is gone. */
export const importsMixed: LibraryImports = {
  version: 4,
  imports: [
    {
      section: 'oracles',
      key: 'names',
      label: 'Names',
      pack: fantasyBasics,
      status: 'current',
      editedLocally: false,
    },
    {
      section: 'trackers',
      key: 'fame',
      label: 'Fame',
      pack: null,
      status: 'removed',
      editedLocally: false,
    },
    {
      section: 'tags',
      key: 'social',
      label: 'Social',
      pack: fantasyBasics,
      status: 'changed',
      editedLocally: false,
    },
    {
      section: 'scene-types',
      key: 'tavern',
      label: 'Tavern',
      pack: fantasyBasics,
      status: 'changed',
      editedLocally: true,
    },
  ],
}

/** A draft that imported nothing yet. */
export const importsNone: LibraryImports = { version: 4, imports: [] }

/** The Scene Type and what it needs: nothing is kept, nothing conflicts. */
export const previewClean: LibraryImportPreview = {
  imports: [
    { section: 'oracles', key: 'names' },
    { section: 'tags', key: 'social' },
    { section: 'scene-types', key: 'tavern' },
  ],
  overrides: [],
  conflicts: [],
  error: null,
}

/** The draft already has the tag `social`: it keeps its own. */
export const previewWithOverride: LibraryImportPreview = {
  ...previewClean,
  imports: [
    { section: 'oracles', key: 'names' },
    { section: 'scene-types', key: 'tavern' },
  ],
  overrides: [{ section: 'tags', key: 'social' }],
}

/** The draft already has the requested Scene Type: the import would be refused. */
export const previewConflict: LibraryImportPreview = {
  imports: [],
  overrides: [],
  conflicts: [{ section: 'scene-types', key: 'tavern' }],
  error: null,
}

/** The result would break an item rule. */
export const previewError: LibraryImportPreview = {
  ...previewClean,
  error: {
    path: 'sceneTypes[0].closing[0]',
    message: 'tracker "fame" is not a tracker of the draft',
  },
}
