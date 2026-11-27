import type { ReactNode } from 'react'

/**
 * The keyboard and button path of the Map's gestures (D13): what can be done to the node last
 * focused or pressed on the Map, as plain buttons beside the drawing. `children` are its controls.
 */
export function MapActionBar({ title, children }: { title: string | null; children?: ReactNode }) {
  return (
    <div role="group" aria-label="Map actions" className="flex flex-wrap items-center gap-2">
      {title === null ? (
        <p className="text-sm text-muted-foreground">
          Pick a phase on the map to move it or change its mode.
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
