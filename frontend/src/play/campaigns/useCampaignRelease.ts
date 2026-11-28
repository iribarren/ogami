import { useQuery } from '@tanstack/react-query'

import { useApiClient } from '@/shared/api/useApiClient'
import { readRelease, type Release } from '@/shared/print/release'

import { campaignError, campaignKey } from './useCampaigns'

/** The URLs of the images the release pinned, by where they show; null where it pinned none. */
export interface TableImages {
  cardFront: string | null
  cardBack: string | null
}

/**
 * The release a campaign is pinned to, in the two shapes the shared readers take: `release` for
 * `buildCards`, and the raw `content` for `flowDiagramFromReleaseContent`, both unchanged.
 */
export interface CampaignRelease {
  gameSystemKey: string
  version: number
  release: Release
  /** The content as the API gave it, for what reads it whole (the flow diagram). */
  content: unknown
  images: TableImages
}

export const campaignReleaseKey = (campaignId: string) =>
  [...campaignKey(campaignId), 'release'] as const

/** Where a campaign's image bytes are read; any other URL in the release is refused. */
const IMAGE_URL = /^\/api\/campaigns\/[^/]+\/card-images\/[0-9a-f]{64}$/

/**
 * `GET /api/campaigns/{campaignId}/release`: the release the campaign is pinned to, read for the
 * table (ADR 0054). A pinned release never changes, so it is not fetched again while the pin
 * holds; an upgrade removes it (`useUpgradeCampaignRelease`) so the next read is the new pin. A
 * content the print reader cannot read is an error with the reader's message, not a crash.
 */
export function useCampaignRelease(campaignId: string) {
  const api = useApiClient()

  return useQuery({
    queryKey: campaignReleaseKey(campaignId),
    queryFn: async ({ signal }): Promise<CampaignRelease> => {
      const { data, error, response } = await api.GET('/api/campaigns/{campaignId}/release', {
        params: { path: { campaignId } },
        signal,
      })
      if (data === undefined) {
        throw campaignError(response, error, [404], 'Loading the campaign release')
      }
      const images: TableImages = { cardFront: null, cardBack: null }
      for (const pin of data.cardImages) {
        // The typed client does not check what arrives: a body of another shape is refused here.
        const slot: string = pin.slot
        if (slot !== 'cardFront' && slot !== 'cardBack') {
          throw new Error('The release pins a card image for a slot the table does not show.')
        }
        if (!IMAGE_URL.test(pin.url)) {
          throw new Error(`The release pins the ${slot} image at an address the table refuses.`)
        }
        images[slot] = pin.url
      }
      return {
        gameSystemKey: data.gameSystemKey,
        version: data.version,
        release: readRelease(data.content),
        content: data.content,
        images,
      }
    },
    staleTime: Infinity,
    // A missing campaign, a reader error or a refusal will not change by asking again.
    retry: false,
  })
}
