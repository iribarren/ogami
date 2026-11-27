import type { DiagramDrag } from '@/shared/flowDiagram'

/** A Scene Type of the GameSystem, as the palette lists it. */
export interface PaletteScene {
  key: string
  name: string
}

/**
 * The Scene Types of the GameSystem beside the Map, each a drag source (`palette:<key>`) to drop into
 * a phase. It is the pointer's way in; the action bar's Add Scene Type is the keyboard's.
 */
export function ScenePalette({
  sceneTypes,
  drag,
  locked,
}: {
  sceneTypes: PaletteScene[]
  drag: DiagramDrag
  /** A save is on its way: the draft cannot be edited from the Map. */
  locked: boolean
}) {
  return (
    <aside aria-label="Scene Type palette" className="space-y-2 lg:w-48 lg:shrink-0">
      <p className="text-sm font-medium">Scene Types</p>
      {sceneTypes.length === 0 ? (
        <p className="text-sm text-muted-foreground">This GameSystem has no Scene Types yet.</p>
      ) : (
        <>
          <p className="text-sm text-muted-foreground">Drag one into a phase to add it.</p>
          <ul aria-label="Scene Types to drag" className="flex flex-wrap gap-2 lg:flex-col">
            {sceneTypes.map(({ key, name }) => (
              <li
                key={key}
                data-palette-item={key}
                className={`rounded-md border bg-background px-2 py-1 text-sm select-none ${locked ? 'opacity-60' : 'cursor-grab'}`}
                {...(locked ? {} : drag.bind(`palette:${key}`, name))}
              >
                {name}
              </li>
            ))}
          </ul>
        </>
      )}
    </aside>
  )
}
