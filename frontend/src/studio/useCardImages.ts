import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import type { components } from '@/shared/api/schema'
import { useApiClient } from '@/shared/api/useApiClient'

import { gameSystemKey, StudioError, studioError } from './useGameSystems'

export type CardImage = components['schemas']['CardImageResponse']
export type CardImageSlot = CardImage['slot']
export type CardImageRefusal = components['schemas']['CardImageRefusedResponse']['reason']

export const cardImagesKey = (key: string) => [...gameSystemKey(key), 'card-images'] as const

/**
 * What each refusal of an upload means for the person (ADR 0053), from the stable `reason` code.
 * The numbers are the limits of the image rules; a code this build does not know falls back to
 * the API's own message.
 */
export const refusalMessages: Record<CardImageRefusal, string> = {
  missing_file: 'Choose an image file to upload.',
  unsupported_format: 'Only PNG and JPEG images are accepted.',
  unreadable: 'The file is not a readable PNG or JPEG image.',
  too_large: 'The image is larger than 5 MB.',
  too_few_pixels: 'The image is too small: it must be at least 408 × 555 px.',
  too_many_pixels: 'The image is too big: it must be at most 1630 × 2220 px.',
  wrong_aspect_ratio:
    'The image does not have the proportions of a card with bleed: 69:94, give or take 2 %.',
}

/** `GET /api/studio/game-systems/{key}/card-images`: what each slot holds, in slot order. */
export function useCardImages(key: string) {
  const api = useApiClient()

  return useQuery({
    queryKey: cardImagesKey(key),
    queryFn: async ({ signal }): Promise<CardImage[]> => {
      const { data, error, response } = await api.GET(
        '/api/studio/game-systems/{key}/card-images',
        { params: { path: { key } }, signal },
      )
      if (data !== undefined) {
        return data
      }
      throw studioError(response, error, [404], 'Loading the card images')
    },
  })
}

/**
 * `PUT /api/studio/game-systems/{key}/card-images/{slot}`: the image goes in the slot, replacing
 * the one there. A refused image (422) answers with its `reason`, kept as the error's `code`.
 */
export function useUploadCardImage(key: string, slot: CardImageSlot) {
  const api = useApiClient()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (file: File): Promise<CardImage> => {
      const { data, error, response } = await api.PUT(
        '/api/studio/game-systems/{key}/card-images/{slot}',
        {
          params: { path: { key, slot } },
          // The schema types a file as a string; the serializer sends the real one as multipart.
          body: { image: file as unknown as string },
          bodySerializer: () => {
            const form = new FormData()
            form.append('image', file)
            return form
          },
        },
      )
      if (data !== undefined) {
        return data
      }
      if (response.status === 422 && 'reason' in error) {
        // Looked up by any string: the API may name a reason this build does not know.
        const known: Partial<Record<string, string>> = refusalMessages
        throw new StudioError(known[error.reason] ?? error.error, 422, error.reason)
      }
      if (response.status === 413) {
        throw new StudioError('The upload is too large for the server: the limit is 5 MB.', 413)
      }
      if (response.status === 404) {
        throw new StudioError('This GameSystem no longer exists, or it is not one of yours.', 404)
      }
      throw studioError(response, undefined, [], 'Uploading the image')
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: cardImagesKey(key) }),
  })
}

/** `DELETE /api/studio/game-systems/{key}/card-images/{slot}`: empties the slot. */
export function useRemoveCardImage(key: string, slot: CardImageSlot) {
  const api = useApiClient()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (): Promise<void> => {
      const { error, response } = await api.DELETE(
        '/api/studio/game-systems/{key}/card-images/{slot}',
        { params: { path: { key, slot } } },
      )
      if (response.ok) {
        return
      }
      throw studioError(response, error, [404], 'Removing the image')
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: cardImagesKey(key) }),
  })
}
