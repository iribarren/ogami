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
      for (const pin of data.cardImages) {
        if (!SHA256.test(pin.sha256)) {
          throw new Error(`The release pins the ${pin.slot} image with a malformed hash.`)
        }
        images[pin.slot] =
          `/api/studio/game-systems/${encodeURIComponent(key)}/images/${pin.sha256}`
      }
      return { release: readRelease(data.content), images }
    },
    // A missing release, a reader error or a refusal will not change by asking again.
    retry: false,
    enabled,
  })
}
