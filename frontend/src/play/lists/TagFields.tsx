import { X } from 'lucide-react'

import { Button } from '@/shared/ui/button'
import { Input } from '@/shared/ui/input'
import { Label } from '@/shared/ui/label'

import type { Campaign } from '../campaigns/useCampaigns'
import type { useFreeTags } from './useFreeTags'

export type ReleaseTag = Campaign['tags'][number]

const chip = 'rounded-md px-1.5 py-0.5 text-xs font-medium'

/** The Tags and free tags of an NPC, a Thread or a Character, as chips. */
export function TagChips({
  tags,
  freeTags,
  releaseTags,
}: {
  tags: string[]
  freeTags: string[]
  releaseTags: ReleaseTag[]
}) {
  if (tags.length === 0 && freeTags.length === 0) {
    return null
  }
  return (
    <div className="flex flex-wrap gap-1">
      {tags.map((key) => {
        // A key the release no longer lists shows as it is.
        const tag = releaseTags.find((held) => held.key === key)
        return (
          <span
            key={key}
            title={tag?.hint ?? undefined}
            className={`${chip} bg-muted text-muted-foreground`}
          >
            {tag?.label ?? key}
          </span>
        )
      })}
      {freeTags.map((label) => (
        <span key={label} className={`${chip} border border-dashed border-border`}>
          {label}
        </span>
      ))}
    </div>
  )
}

/**
 * The release's Tags that apply to `kind` as checkboxes named `tags` (read from the form), then
 * the free tags editor. After them come the Tags the item holds that are not offered (the release
 * no longer lists them, or they no longer apply to this kind), so saving keeps them and the
 * player can remove them.
 */
export function TagFields({
  id,
  kind,
  releaseTags,
  held,
  free,
}: {
  id: string
  /** What the form edits: a Tag limited to other kinds is not offered. */
  kind: 'character' | 'npc' | 'thread'
  releaseTags: ReleaseTag[]
  /** The Tag keys the item holds. */
  held: string[]
  free: ReturnType<typeof useFreeTags>
}) {
  const offered = releaseTags.filter((tag) => tag.appliesTo?.includes(kind) ?? true)
  const options: ReleaseTag[] = [
    ...offered,
    ...held
      .filter((key) => !offered.some((tag) => tag.key === key))
      .map(
        (key) =>
          releaseTags.find((tag) => tag.key === key) ?? {
            key,
            label: key,
            hint: null,
            appliesTo: null,
          },
      ),
  ]

  return (
    <>
      {options.length > 0 && (
        <fieldset className="space-y-1">
          <legend className="text-sm font-medium">Tags</legend>
          {options.map((tag) => (
            <label key={tag.key} title={tag.hint ?? undefined} className="flex items-start gap-2">
              <input
                type="checkbox"
                name="tags"
                value={tag.key}
                defaultChecked={held.includes(tag.key)}
                className="mt-1"
              />
              <span>
                {tag.label}
                {tag.hint !== null && (
                  <span className="block text-xs text-muted-foreground">{tag.hint}</span>
                )}
              </span>
            </label>
          ))}
        </fieldset>
      )}
      <div className="space-y-1">
        <Label htmlFor={`${id}-free`}>Free tags</Label>
        {free.freeTags.length > 0 && (
          <ul className="flex flex-wrap gap-1">
            {free.freeTags.map((label, index) => (
              <li
                key={index}
                className={`${chip} flex items-center gap-1 border border-dashed border-border`}
              >
                {label}
                <button
                  type="button"
                  aria-label={`Remove free tag ${label}`}
                  onClick={() => {
                    free.remove(index)
                  }}
                >
                  <X aria-hidden="true" className="size-3" />
                </button>
              </li>
            ))}
          </ul>
        )}
        <div className="flex gap-2">
          <Input
            id={`${id}-free`}
            value={free.draft}
            aria-describedby={`${id}-free-help`}
            onChange={(event) => {
              free.setDraft(event.target.value)
            }}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                event.preventDefault()
                free.add()
              }
            }}
          />
          <Button type="button" variant="outline" size="sm" onClick={free.add}>
            Add free tag
          </Button>
        </div>
        <p id={`${id}-free-help`} className="text-xs text-muted-foreground">
          One tag at a time; commas are part of the tag.
        </p>
      </div>
    </>
  )
}
