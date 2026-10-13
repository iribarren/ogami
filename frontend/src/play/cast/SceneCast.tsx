import { useId, useState } from 'react'

import { Button } from '@/shared/ui/button'
import { NativeSelect } from '@/shared/ui/native-select'

import type { Campaign } from '../campaigns/useCampaigns'
import { useEditSceneCast } from './useSceneCast'

type Scene = Campaign['sessions'][number]['scenes'][number]

interface SceneCastProps {
  campaignId: string
  /** Every NPC of the campaign, open or closed: any can join a cast. */
  npcs: Campaign['npcs']
  /** Every Character of the campaign: any can join a cast. */
  characters: Campaign['characters']
  sessionNumber: number
  scene: Pick<Scene, 'number' | 'cast' | 'characters'>
  /** Only the current scene's cast is edited; a past scene's is read-only. */
  editable: boolean
}

/**
 * The NPCs and Characters in a scene. In the current scene the player edits the cast: "Edit
 * cast" shows a remove button on each member and a select to add any other NPC or Character of
 * the campaign. Each change sends the whole new cast, NPCs and Characters apart.
 */
export function SceneCast({
  campaignId,
  npcs,
  characters,
  sessionNumber,
  scene,
  editable,
}: SceneCastProps) {
  const [editing, setEditing] = useState(false)
  const edit = useEditSceneCast(campaignId)
  const addId = useId()
  const [toAdd, setToAdd] = useState('')
  const npcIds = scene.cast.map(({ id }) => id)
  const characterIds = scene.characters.map(({ id }) => id)
  const members = [
    ...scene.cast.map(({ id, name }) => ({ value: `npc:${id}`, name })),
    ...scene.characters.map(({ id, name }) => ({ value: `character:${id}`, name })),
  ]
  const addable = [
    ...npcs
      .filter(({ id }) => !npcIds.includes(id))
      .map(({ id, name, status }) => ({
        value: `npc:${id}`,
        label: status === 'closed' ? `${name} (closed)` : name,
      })),
    ...characters
      .filter(({ id }) => !characterIds.includes(id))
      .map(({ id, name }) => ({ value: `character:${id}`, label: `${name} (Character)` })),
  ]
  // The selection falls back to the first member that can still be added.
  const selected = addable.some(({ value }) => value === toAdd) ? toAdd : (addable[0]?.value ?? '')
  const send = (next: { npcIds: string[]; characterIds: string[] }) => {
    edit.mutate(next)
  }
  const without = (value: string) => {
    send({
      npcIds: npcIds.filter((id) => `npc:${id}` !== value),
      characterIds: characterIds.filter((id) => `character:${id}` !== value),
    })
  }
  const withSelected = () => {
    const [kind, id = ''] = selected.split(':')
    send(
      kind === 'npc'
        ? { npcIds: [...npcIds, id], characterIds }
        : { npcIds, characterIds: [...characterIds, id] },
    )
  }
  if (!editable && members.length === 0) {
    return null
  }

  return (
    <div
      role="group"
      aria-label={`Cast of session ${String(sessionNumber)}, scene ${String(scene.number)}`}
      className="space-y-2 text-sm"
    >
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span className="text-muted-foreground">Cast:</span>
        {members.length === 0 ? (
          <span>No cast</span>
        ) : (
          <ul className="flex flex-wrap gap-x-3 gap-y-1">
            {members.map(({ value, name }) => (
              <li key={value} className="flex items-center gap-1">
                <span className="font-medium">{name}</span>
                {editing && (
                  <Button
                    type="button"
                    variant="ghost"
                    size="xs"
                    aria-label={`Remove ${name}`}
                    disabled={edit.isPending}
                    onClick={() => {
                      without(value)
                    }}
                  >
                    ×
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}
        {editable && (
          <Button
            type="button"
            variant="ghost"
            size="xs"
            onClick={() => {
              setEditing(!editing)
            }}
          >
            {editing ? 'Done' : 'Edit cast'}
          </Button>
        )}
      </div>
      {editable && editing && (
        <div className="flex items-center gap-2">
          <label htmlFor={addId} className="sr-only">
            Add to the cast
          </label>
          <NativeSelect
            id={addId}
            value={selected}
            disabled={addable.length === 0}
            onChange={(event) => {
              setToAdd(event.target.value)
            }}
          >
            {addable.map(({ value, label }) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </NativeSelect>
          <Button
            type="button"
            size="sm"
            disabled={edit.isPending || selected === ''}
            onClick={withSelected}
          >
            Add
          </Button>
        </div>
      )}
      {edit.error && (
        <p role="alert" className="text-destructive">
          {edit.error.message}
        </p>
      )}
    </div>
  )
}
