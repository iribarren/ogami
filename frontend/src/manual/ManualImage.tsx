import { cn } from '@/shared/lib/utils'

import type { ImageSize } from './editionBlocks'
import { imageUrl } from './images/lookup'
import { imagePrompt } from './images/styles'

/**
 * The box of each size: banner full width at 3:1, half and spot floated right at 4:3 and 1:1, and
 * full width on a phone. The Paper edition sizes the same `data-image-size` in `print/print.css`.
 */
const boxes =
  'my-4 data-[image-size=banner]:aspect-[3/1] data-[image-size=half]:aspect-[4/3] data-[image-size=spot]:aspect-square ' +
  'sm:data-[image-size=half]:float-right sm:data-[image-size=half]:ml-6 sm:data-[image-size=half]:w-[45%] ' +
  'sm:data-[image-size=spot]:float-right sm:data-[image-size=spot]:ml-6 sm:data-[image-size=spot]:w-[30%]'

/**
 * An `::image` directive: the illustration when its file is in `images/`, otherwise a placeholder
 * of the same box showing the file name, the alt text and the full prompt for the image tool. An
 * unknown style throws, so a broken directive never shows a half page. No caption.
 */
export function ManualImage({
  file,
  style,
  size,
  alt,
  subject,
  eager = false,
}: {
  file: string
  style: string
  size: ImageSize
  alt: string
  subject: string
  eager?: boolean
}) {
  const prompt = imagePrompt(style, subject)
  const url = imageUrl(file)
  return (
    <figure data-image-size={size} className={cn(boxes, 'clear-both')}>
      {url === undefined ? (
        <div
          data-image-placeholder
          className="h-full min-h-full w-full space-y-1 overflow-auto rounded-md border-2 border-dashed p-3 text-xs"
        >
          <p className="font-semibold">Illustration to come</p>
          <p>{file}</p>
          <p>{alt}</p>
          <p>{prompt}</p>
        </div>
      ) : (
        <img
          src={url}
          alt={alt}
          loading={eager ? 'eager' : 'lazy'}
          className="h-full w-full rounded-md object-cover"
        />
      )}
    </figure>
  )
}
