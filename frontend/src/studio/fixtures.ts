import type { GameSystem, GameSystemSummary } from './useGameSystems'

// Shared by the Studio tests and stories.

export const published: GameSystemSummary = {
  key: 'noir-detective',
  name: 'Noir detective',
  schemaVersion: 2,
  latestReleaseVersion: 3,
  hasUnpublishedChanges: false,
  updatedAt: '2026-10-14T09:00:00+00:00',
}

export const withChanges: GameSystemSummary = {
  key: 'ash-and-iron',
  name: 'Ash and iron',
  schemaVersion: 2,
  latestReleaseVersion: 1,
  hasUnpublishedChanges: true,
  updatedAt: '2026-10-15T09:00:00+00:00',
}

export const neverPublished: GameSystemSummary = {
  key: 'my-draft',
  name: 'My draft',
  schemaVersion: 2,
  latestReleaseVersion: null,
  hasUnpublishedChanges: true,
  updatedAt: '2026-10-16T09:00:00+00:00',
}

/** A schema version 2 draft that was never published. */
export const draft: GameSystem = {
  key: 'my-draft',
  name: 'My draft',
  description: 'A game of my own.',
  credits: { notice: 'Unofficial fan content.', license: 'CC BY 4.0', url: null },
  theme: 'noir',
  schemaVersion: 2,
  sectionCounts: { oracleTables: 2, trackers: 1, flows: 0, checks: 3 },
  latestReleaseVersion: null,
  hasUnpublishedChanges: true,
  createdAt: '2026-10-15T09:00:00+00:00',
  updatedAt: '2026-10-16T09:00:00+00:00',
}

/** A published, unchanged schema version 1 GameSystem: no credits, no theme. */
export const legacy: GameSystem = {
  key: 'free-journal',
  name: 'Free journal',
  description: null,
  credits: null,
  theme: null,
  schemaVersion: 1,
  sectionCounts: { oracles: 4, flowSteps: 0 },
  latestReleaseVersion: 2,
  hasUnpublishedChanges: false,
  createdAt: '2026-09-01T09:00:00+00:00',
  updatedAt: '2026-09-02T09:00:00+00:00',
}

export const validDraft = { valid: true, error: null, warnings: [] }

/** A published, changed GameSystem: the next publish creates version 4. */
export const published3: GameSystem = {
  ...draft,
  key: 'noir-detective',
  name: 'Noir detective',
  latestReleaseVersion: 3,
}
