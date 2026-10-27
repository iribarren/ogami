import type { components } from '@/shared/api/schema'

import darkPack from './dark-pack.png'

type Badge = NonNullable<components['schemas']['CreditsResponse']['badge']>

/**
 * The logo of each credits badge a release can name (ADR 0044), with its alternative text. A release
 * never carries an image: a new badge is a new value of the API enum and its image, added together
 * (the type fails to compile until the map has it).
 */
export const creditsBadges: Record<Badge, { src: string; alt: string }> = {
  'dark-pack': { src: darkPack, alt: 'Dark Pack logo' },
}
