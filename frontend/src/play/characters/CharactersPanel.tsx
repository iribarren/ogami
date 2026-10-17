import { useState } from 'react'

import { summarize } from '@/shared/sheet/sheetValues'
import { Button } from '@/shared/ui/button'

import type { Campaign } from '../campaigns/useCampaigns'
import { RollCheckDialog } from '../checks/RollCheckDialog'
import { TagChips } from '../lists/TagFields'
import { CharacterForm } from './CharacterForm'
import { sheetTemplate } from './sheetTemplate'
import { useCharacterActions, type Character, type CharacterFields } from './useCharacters'

/**
 * The Characters of a campaign, each with its Tags and a summary of its sheet, an inline form to
 * add and edit, and a dialog to roll a check when the release has any. There is no deleting a
 * Character. Only the Characters list is shown here; a release without sheet fields gives name
 * and Tags only.
 */
export function CharactersPanel({ campaign }: { campaign: Campaign }) {
  const { save } = useCharacterActions(campaign.id)
  const sheet = campaign.pinnedRelease.sheet
  const template = sheetTemplate(campaign.pinnedRelease)
  // The id of the Character being edited, 'new' for the add form, none when no form is open.
  const [form, setForm] = useState<string | null>(null)

  function openForm(next: string | null) {
    save.reset()
    setForm(next)
  }

  function renderForm(character: Character | null) {
    return (
      <CharacterForm
        character={character}
        sheet={template}
        trackers={campaign.trackers}
        releaseTags={campaign.tags}
        pending={save.isPending}
        error={save.error?.message}
        onSubmit={(fields: CharacterFields) => {
          save.mutate(
            { id: character?.id ?? null, fields },
            {
              onSuccess: () => {
                setForm(null)
              },
            },
          )
        }}
        onCancel={() => {
          openForm(null)
        }}
      />
    )
  }

  return (
    <div className="space-y-3">
      {campaign.characters.length === 0 ? (
        <p className="text-sm text-muted-foreground">No Characters yet.</p>
      ) : (
        <ul className="space-y-3">
          {campaign.characters.map((character) =>
            form === character.id ? (
              <li key={character.id}>{renderForm(character)}</li>
            ) : (
              <li key={character.id} className="space-y-1">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <span className="font-medium">{character.name}</span>
                  <div className="flex gap-1">
                    {campaign.checks.length > 0 && (
                      <RollCheckDialog campaign={campaign} character={character} />
                    )}
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      aria-label={`Edit ${character.name}`}
                      onClick={() => {
                        openForm(character.id)
                      }}
                    >
                      Edit
                    </Button>
                  </div>
                </div>
                <p className="text-sm text-muted-foreground">
                  {sheet
                    .map((field) =>
                      summarize(field, character.values[field.key], character.derived),
                    )
                    .filter((part) => part !== null)
                    .join(' · ')}
                </p>
                <TagChips
                  tags={character.tags}
                  freeTags={character.freeTags}
                  releaseTags={campaign.tags}
                />
              </li>
            ),
          )}
        </ul>
      )}
      {form === 'new' ? (
        renderForm(null)
      ) : (
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => {
            openForm('new')
          }}
        >
          Add Character
        </Button>
      )}
    </div>
  )
}
