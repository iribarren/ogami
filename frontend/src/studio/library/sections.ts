import type { components } from '@/shared/api/schema'

export type LibrarySection = components['schemas']['LibraryItemRefResponse']['section']

/** The sections of a library pack, in the order the library shows them. */
export const sectionOrder: readonly LibrarySection[] = [
  'oracles',
  'trackers',
  'tags',
  'fact-slots',
  'scene-types',
]

/** How the section names read. */
export const sectionTitles: Record<LibrarySection, string> = {
  oracles: 'Oracles',
  trackers: 'Trackers',
  tags: 'Tags',
  'fact-slots': 'Fact slots',
  'scene-types': 'Scene Types',
}
