import { ImageIcon } from 'lucide-react'
import { useId, useRef, useState } from 'react'

import { Alert } from '@/shared/ui/alert'
import { Button } from '@/shared/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/shared/ui/dialog'
import { IconTile } from '@/shared/ui/icon-tile'

import {
  useCardImages,
  useRemoveCardImage,
  useUploadCardImage,
  type CardImage,
  type CardImageSlot,
} from './useCardImages'

/** The slots in the order they show, with the name the person sees and what the image is for. */
const slots: readonly { slot: CardImageSlot; label: string; hint: string }[] = [
  { slot: 'cardBack', label: 'Card back', hint: 'Printed on the back of every card.' },
  {
    slot: 'cardFront',
    label: 'Card frame',
    hint: 'Printed behind the text on the front of every card.',
  },
]

/**
 * The card images of a GameSystem draft (ADR 0053): a card back and a front frame for the cards
 * Studio prints. Without them the cards print plain. A release keeps the images it was published
 * with, so changing them here never changes what a published release prints.
 */
export function CardImagesSection({ gameSystemKey }: { gameSystemKey: string }) {
  const images = useCardImages(gameSystemKey)
  const headingId = useId()

  return (
    <section aria-labelledby={headingId} className="panel">
      <div className="panel-head">
        <IconTile size="sm">
          <ImageIcon />
        </IconTile>
        <h2 id={headingId} className="font-heading text-panel-title">
          Card images
        </h2>
      </div>
      <div className="space-y-4 panel-body">
        <Requirements />
        {images.isPending && <p className="text-muted-foreground">Loading the card images…</p>}
        {images.isError && (
          <Alert role="alert">
            <p>{images.error.message}</p>
          </Alert>
        )}
        {images.data !== undefined && (
          <div className="grid gap-4 sm:grid-cols-2">
            {slots.map((slot) => (
              <SlotPanel
                key={slot.slot}
                gameSystemKey={gameSystemKey}
                {...slot}
                image={images.data.find((image) => image.slot === slot.slot)}
              />
            ))}
          </div>
        )}
      </div>
    </section>
  )
}

/** The image rules of ADR 0053, as the server checks them. */
function Requirements() {
  return (
    <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
      <li>PNG or JPEG, at most 5 MB.</li>
      <li>
        Recommended 815 × 1110 px: a 63 × 88 mm card plus 3 mm bleed (69 × 94 mm) at 300 dpi.
        Accepted from 408 × 555 px up to 1630 × 2220 px.
      </li>
      <li>The proportions must be 69:94, give or take 2 %.</li>
      <li>On the frame, the text sits in a 5 mm safe area inside the card: keep it clear.</li>
    </ul>
  )
}

function SlotPanel({
  gameSystemKey,
  slot,
  label,
  hint,
  image,
}: {
  gameSystemKey: string
  slot: CardImageSlot
  label: string
  hint: string
  image: CardImage | undefined
}) {
  const upload = useUploadCardImage(gameSystemKey, slot)
  const remove = useRemoveCardImage(gameSystemKey, slot)
  const [confirming, setConfirming] = useState(false)
  const input = useRef<HTMLInputElement>(null)
  const verb = image === undefined ? 'Upload' : 'Replace'

  return (
    <div role="group" aria-label={label} className="space-y-3 rounded-md border p-4">
      <div>
        <h3 className="font-medium">{label}</h3>
        <p className="text-sm text-muted-foreground">{hint}</p>
      </div>
      {image === undefined ? (
        <p className="text-sm text-muted-foreground">No image: the cards print plain.</p>
      ) : (
        <img
          src={image.url}
          alt={`${label} preview`}
          width={image.width}
          height={image.height}
          className="max-h-64 w-auto rounded border bg-muted object-contain"
        />
      )}
      {image !== undefined && (
        <p className="text-xs text-muted-foreground">
          {image.width} × {image.height} px, {image.mediaType === 'image/png' ? 'PNG' : 'JPEG'}
        </p>
      )}
      <input
        ref={input}
        type="file"
        accept="image/png,image/jpeg"
        aria-label={`${verb} ${label.toLowerCase()} file`}
        className="sr-only"
        tabIndex={-1}
        onChange={(event) => {
          const file = event.target.files?.[0]
          // The same file can be chosen again after a refusal.
          event.target.value = ''
          if (file) {
            remove.reset()
            upload.mutate(file)
          }
        }}
      />
      <div className="flex flex-wrap gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={upload.isPending}
          onClick={() => input.current?.click()}
        >
          {upload.isPending ? 'Uploading…' : `${verb} ${label.toLowerCase()}`}
        </Button>
        {image !== undefined && (
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={upload.isPending}
            onClick={() => {
              upload.reset()
              remove.reset()
              setConfirming(true)
            }}
          >
            Remove {label.toLowerCase()}
          </Button>
        )}
      </div>
      {upload.error && (
        <Alert role="alert">
          <p>{upload.error.message}</p>
        </Alert>
      )}
      <Dialog open={confirming} onOpenChange={setConfirming}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Remove the {label.toLowerCase()}?</DialogTitle>
            <DialogDescription>
              The cards you print from the next release print without it. Releases already published
              keep the image they were published with.
            </DialogDescription>
          </DialogHeader>
          {remove.error && (
            <p role="alert" className="text-sm text-destructive">
              {remove.error.message}
            </p>
          )}
          <div className="flex justify-end gap-2">
            <Button
              variant="outline"
              onClick={() => {
                setConfirming(false)
              }}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              disabled={remove.isPending}
              onClick={() => {
                remove.mutate(undefined, {
                  onSuccess: () => {
                    setConfirming(false)
                  },
                })
              }}
            >
              Remove the {label.toLowerCase()}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
