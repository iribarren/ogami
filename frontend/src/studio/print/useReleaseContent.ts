import { useQuery } from '@tanstack/react-query'

import { useApiClient } from '@/shared/api/useApiClient'
import { readRelease, type Release } from '@/shared/print/release'

import { gameSystemKey, StudioError, studioError } from '../useGameSystems'

/** The URLs of the images a release pinned, by where they print; null where it pinned none. */
export interface PinnedImages {
  cardFront: string | null
  cardBack: string | null
}

export interface ReleaseContent {
  release: Release
  images: PinnedImages
}

const SHA256 = /^[0-9a-f]{64}$/

/**
 * `GET /api/studio/game-systems/{key}/releases/{version}/content`: what a published release
 * prints, and the images it pinned. The image URLs are made from the pinned hash, never taken from
 * the draft; a hash that is not 64 hexadecimal digits is refused. A content the print reader
 * cannot read is an error with the reader's message, not a crash.
 */
export function useReleaseContent(key: string, version: number, enabled = true) {
  const api = useApiClient()

  return useQuery({
    queryKey: [...gameSystemKey(key), 'releases', version, 'content'] as const,
    queryFn: async ({ signal }): Promise<ReleaseContent> => {
      const { data, error, response } = await api.GET(
        '/api/studio/game-systems/{key}/releases/{version}/content',
        { params: { path: { key, version: String(version) } }, signal },
      )
      if (data === undefined) {
        if (response.status === 404) {
          throw new StudioError(
            `Release ${String(version)} not found: this GameSystem has no such release, or it is not one of yours.`,
            404,
          )
        }
        throw studioError(response, error, [], 'Loading the release content')
      }
      const images: PinnedImages = { cardFront: null, cardBack: null }
      // The typed client does not check what arrives: a body of another shape is refused here.
      const pins: unknown = (data as { cardImages?: unknown }).cardImages
      if (!Array.isArray(pins)) {
        throw new Error('The release content has no list of pinned card images (cardImages).')
      }
      for (const item of pins as unknown[]) {
        const pin = (typeof item === 'object' && item !== null ? item : {}) as {
          slot?: unknown
          sha256?: unknown
        }
        const slot = pin.slot
        if (slot !== 'cardFront' && slot !== 'cardBack') {
          throw new Error('The release pins a card image for a slot this page does not print.')
        }
        if (typeof pin.sha256 !== 'string' || !SHA256.test(pin.sha256)) {
          throw new Error(`The release pins the ${slot} image with a malformed hash.`)
        }
        images[slot] = `/api/studio/game-systems/${encodeURIComponent(key)}/images/${pin.sha256}`
      }
      return { release: readRelease(data.content), images }
    },
    // A missing release, a reader error or a refusal will not change by asking again.
    retry: false,
    enabled,
  })
}
