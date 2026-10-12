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
  sessionNumber: number
  scene: Pick<Scene, 'number' | 'cast'>
  /** Only the current scene's cast is edited; a past scene's is read-only. */
  editable: boolean
}

/**
 * The NPCs in a scene. In the current scene the player edits the cast: "Edit cast" shows a
 * remove button on each NPC and a select to add any other NPC of the campaign. Each change
 * sends the whole new cast.
 */
export function SceneCast({ campaignId, npcs, sessionNumber, scene, editable }: SceneCastProps) {
  const [editing, setEditing] = useState(false)
  const edit = useEditSceneCast(campaignId)
  const addId = useId()
  const [toAdd, setToAdd] = useState('')
  const inCast = new Set(scene.cast.map(({ id }) => id))
  const addable = npcs.filter(({ id }) => !inCast.has(id))
  // The selection falls back to the first NPC that can still be added.
  const selected = addable.some(({ id }) => id === toAdd) ? toAdd : (addable[0]?.id ?? '')
  const send = (ids: string[]) => {
    edit.mutate(ids)
  }
  if (!editable && scene.cast.length === 0) {
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
        {scene.cast.length === 0 ? (
          <span>No cast</span>
        ) : (
          <ul className="flex flex-wrap gap-x-3 gap-y-1">
            {scene.cast.map(({ id, name }) => (
              <li key={id} className="flex items-center gap-1">
                <span className="font-medium">{name}</span>
                {editing && (
                  <Button
                    type="button"
                    variant="ghost"
                    size="xs"
                    aria-label={`Remove ${name}`}
                    disabled={edit.isPending}
                    onClick={() => {
                      send(scene.cast.map((member) => member.id).filter((held) => held !== id))
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
            {addable.map(({ id, name, status }) => (
              <option key={id} value={id}>
                {status === 'closed' ? `${name} (closed)` : name}
              </option>
            ))}
          </NativeSelect>
          <Button
            type="button"
            size="sm"
            disabled={edit.isPending || selected === ''}
            onClick={() => {
              send([...scene.cast.map(({ id }) => id), selected])
            }}
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
