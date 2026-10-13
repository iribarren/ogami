import { useState } from 'react'

import { Button } from '@/shared/ui/button'

import type { Campaign } from './useCampaigns'
import { useEditParty } from './useParty'

/**
 * The party of the current Session: who plays. "Edit party" lists the Characters of the campaign
 * to tick; the party keeps the order of those who stay and appends the ones added. Hidden before
 * the first Session and for a campaign without Characters.
 */
export function PartyEditor({ campaign }: { campaign: Campaign }) {
  const edit = useEditParty(campaign.id)
  // The party being edited, in order; null when the editor is closed.
  const [draft, setDraft] = useState<string[] | null>(null)
  const session = campaign.sessions.find(({ number }) => number === campaign.currentSessionNumber)
  if (session === undefined || campaign.characters.length === 0) {
    return null
  }

  function toggle(id: string, ticked: boolean) {
    setDraft((held) =>
      ticked ? [...(held ?? []), id] : (held ?? []).filter((kept) => kept !== id),
    )
  }

  return (
    <div role="group" aria-label="Party" className="space-y-1 text-sm">
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span className="text-muted-foreground">Party:</span>
        {session.party.length === 0 ? (
          <span>No party</span>
        ) : (
          <ul className="flex flex-wrap gap-x-3">
            {session.party.map(({ id, name }) => (
              <li key={id} className="font-medium">
                {name}
              </li>
            ))}
          </ul>
        )}
        {draft === null && (
          <Button
            type="button"
            variant="ghost"
            size="xs"
            onClick={() => {
              edit.reset()
              setDraft(session.party.map(({ id }) => id))
            }}
          >
            Edit party
          </Button>
        )}
      </div>
      {draft !== null && (
        <form
          className="space-y-2"
          onSubmit={(event) => {
            event.preventDefault()
            edit.mutate(draft, {
              onSuccess: () => {
                setDraft(null)
              },
            })
          }}
        >
          <ul className="space-y-1">
            {campaign.characters.map(({ id, name }) => (
              <li key={id}>
                <label className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    checked={draft.includes(id)}
                    onChange={(event) => {
                      toggle(id, event.target.checked)
                    }}
                  />
                  {name}
                </label>
              </li>
            ))}
          </ul>
          <div className="flex gap-2">
            <Button type="submit" size="sm" disabled={edit.isPending}>
              Save party
            </Button>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              disabled={edit.isPending}
              onClick={() => {
                edit.reset()
                setDraft(null)
              }}
            >
              Cancel
            </Button>
          </div>
        </form>
      )}
      {edit.error && (
        <p role="alert" className="text-destructive">
          {edit.error.message}
        </p>
      )}
    </div>
  )
}
