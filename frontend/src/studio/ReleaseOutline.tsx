import type { Release } from './useGameSystemReleases'
import { sectionLabels } from './sectionLabels'

/**
 * What a release holds, section by section: each item by its display name and key, in content
 * order. An item without a display name shows its key alone.
 */
export function ReleaseOutline({ outline }: { outline: Release['outline'] }) {
  return (
    <div className="space-y-4">
      {Object.entries(outline).map(([section, items]) => {
        const label = sectionLabels[section] ?? section
        return (
          <div key={section} role="group" aria-label={label} className="space-y-1">
            <h3 className="font-heading font-semibold">
              {label} <span className="font-normal text-muted-foreground">{items.length}</span>
            </h3>
            {items.length === 0 ? (
              <p className="text-sm text-muted-foreground">No items</p>
            ) : (
              <ul className="space-y-0.5 text-sm">
                {items.map(({ key, name }, index) => (
                  // Keys are unique per section in a valid release, but a stored one is not trusted here.
                  <li
                    key={`${String(index)}:${key}`}
                    className="flex flex-wrap items-baseline gap-x-2"
                  >
                    {name !== null && <span>{name}</span>}
                    <span className="font-mono text-xs text-muted-foreground">{key}</span>
                  </li>
                ))}
              </ul>
            )}
          </div>
        )
      })}
    </div>
  )
}
