import { useState } from 'react'

import { Button } from '@/shared/ui/button'

import type { Campaign } from '../campaigns/useCampaigns'
import { CharacterForm } from '../characters/CharacterForm'
import { useCharacterActions } from '../characters/useCharacters'

/**
 * The control of a `character` step: the player picks one of the campaign's Characters, or
 * creates one here (the form is the only way when there is none yet). A new Character is saved
 * first and the step completes with its id; if saving fails the step stays open with the reason.
 */
export function CharacterStepForm({
  campaign,
  pending,
  onPick,
}: {
  campaign: Campaign
  pending: boolean
  onPick: (characterId: string) => void
}) {
  const { save } = useCharacterActions(campaign.id)
  const [creating, setCreating] = useState(false)
  const [chosen, setChosen] = useState('')

  if (creating || campaign.characters.length === 0) {
    return (
      <CharacterForm
        character={null}
        sheet={campaign.pinnedRelease.sheet}
        releaseTags={campaign.tags}
        pending={pending || save.isPending}
        error={save.error?.message}
        onSubmit={(fields) => {
          save.mutate(
            { id: null, fields },
            {
              onSuccess: (character) => {
                // If completing the step is refused, the Character exists and can be picked.
                setChosen(character.id)
                setCreating(false)
                onPick(character.id)
              },
            },
          )
        }}
        onCancel={
          campaign.characters.length === 0
            ? undefined
            : () => {
                save.reset()
                setCreating(false)
              }
        }
      />
    )
  }

  return (
    <form
      className="space-y-2"
      onSubmit={(event) => {
        event.preventDefault()
        onPick(chosen)
      }}
    >
      <div role="radiogroup" aria-label="Characters" className="space-y-1">
        {campaign.characters.map(({ id, name }) => (
          <label key={id} className="flex items-center gap-2">
            <input
              type="radio"
              name="character"
              checked={chosen === id}
              onChange={() => {
                setChosen(id)
              }}
            />
            {name}
          </label>
        ))}
      </div>
      <div className="flex gap-2">
        <Button type="submit" disabled={pending || chosen === ''}>
          Choose
        </Button>
        <Button
          type="button"
          variant="outline"
          disabled={pending}
          onClick={() => {
            setCreating(true)
          }}
        >
          New Character
        </Button>
      </div>
    </form>
  )
}
