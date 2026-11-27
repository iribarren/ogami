import { useState, type ReactNode } from 'react'

import { Button } from '@/shared/ui/button'
import { Select } from '@/shared/ui/select'

import type { PaletteScene } from './ScenePalette'

/**
 * The keyboard and button path of the Map's gestures (D13): what can be done to the node last
 * focused or pressed on the Map, as plain buttons beside the drawing. `children` are its controls.
 */
export function MapActionBar({ title, children }: { title: string | null; children?: ReactNode }) {
  return (
    <div role="group" aria-label="Map actions" className="flex flex-wrap items-center gap-2">
      {title === null ? (
        <p className="text-sm text-muted-foreground">
          Pick a phase or one of its Scene Types on the map to move it, change its mode or edit its
          selection.
        </p>
      ) : (
        <>
          <p className="text-sm font-medium">{title}</p>
          {children}
        </>
      )}
    </div>
  )
}

/** Adds one of the GameSystem's Scene Types to the selection of the phase the bar is on. */
export function AddSceneType({
  sceneTypes,
  disabled,
  onAdd,
}: {
  sceneTypes: PaletteScene[]
  disabled: boolean
  onAdd: (sceneType: string) => void
}) {
  const [picked, setPicked] = useState('')
  return (
    <>
      <Select
        aria-label="Scene Type to add"
        value={picked}
        disabled={disabled || sceneTypes.length === 0}
        onChange={(event) => {
          setPicked(event.target.value)
        }}
      >
        <option value="">
          {sceneTypes.length === 0 ? 'No Scene Type yet' : 'Pick a Scene Type'}
        </option>
        {sceneTypes.map(({ key, name }) => (
          <option key={key} value={key}>
            {name}
          </option>
        ))}
      </Select>
      <Button
        type="button"
        variant="outline"
        size="sm"
        disabled={disabled || picked === ''}
        onClick={() => {
          onAdd(picked)
          setPicked('')
        }}
      >
        Add Scene Type
      </Button>
    </>
  )
}
