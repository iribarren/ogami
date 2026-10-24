import { sectionLabels } from './sectionLabels'
import type { Release } from './useGameSystemReleases'

type Changes = NonNullable<Release['changes']>
type SectionChanges = NonNullable<Changes['sections']>[string]

const hasChanges = ({ added, removed, changed, reordered }: SectionChanges) =>
  added.length > 0 || removed.length > 0 || changed.length > 0 || reordered

/** "1 added, 2 changed · Order changed": what happened in a section, most of it first. */
function summary({ added, removed, changed, reordered }: SectionChanges): string {
  const counts = [
    [added.length, 'added'],
    [removed.length, 'removed'],
    [changed.length, 'changed'],
  ]
    .filter(([count]) => count !== 0)
    .map(([count, what]) => `${String(count)} ${String(what)}`)
    .join(', ')
  return [counts, reordered ? 'Order changed' : ''].filter(Boolean).join(' · ')
}

/**
 * What a release changed against the one before it, in the sections that changed: items added,
 * removed and changed (with the properties that differ), and whether the kept items changed order.
 * The sections without changes are only counted. A release without a previous one, or with another
 * schema version, says so instead.
 */
export function ReleaseChanges({
  changes,
  schemaVersion,
}: {
  changes: Changes | null
  /** The schema version of the release itself. */
  schemaVersion: number
}) {
  if (changes === null) {
    return <p className="text-sm text-muted-foreground">First release: nothing to compare.</p>
  }

  const { previousVersion, previousSchemaVersion, sections } = changes
  const heading = `Changes since version ${String(previousVersion)}`
  if (sections === null) {
    return (
      <div role="group" aria-label={heading} className="space-y-1">
        <h3 className="font-heading font-semibold">{heading}</h3>
        <p className="text-sm text-muted-foreground">
          Version {previousVersion} uses schema version {previousSchemaVersion} and this one{' '}
          {schemaVersion}, so their items are not compared.
        </p>
      </div>
    )
  }

  const entries = Object.entries(sections)
  const changed = entries.filter(([, section]) => hasChanges(section))
  const unchanged = entries.length - changed.length
  return (
    <div role="group" aria-label={heading} className="space-y-3">
      <h3 className="font-heading font-semibold">{heading}</h3>
      {changed.map(([name, section]) => (
        <SectionChangesList key={name} name={name} section={section} />
      ))}
      {(changed.length === 0 || unchanged > 0) && (
        <p className="text-sm text-muted-foreground">
          {changed.length === 0
            ? 'No changes in any section.'
            : `No changes: ${String(unchanged)} ${unchanged === 1 ? 'section' : 'sections'}`}
        </p>
      )}
    </div>
  )
}

function SectionChangesList({ name, section }: { name: string; section: SectionChanges }) {
  const label = sectionLabels[name] ?? name
  return (
    <div role="group" aria-label={label} className="space-y-1">
      <h4 className="font-medium">
        {label}{' '}
        <span className="text-sm font-normal text-muted-foreground">{summary(section)}</span>
      </h4>
      <ul className="space-y-0.5 text-sm">
        {section.added.map(({ key, name }, index) => (
          // Keys are unique per section in a valid release, but a stored one is not trusted here.
          <li key={`+${String(index)}:${key}`} className="flex flex-wrap items-baseline gap-x-2">
            <Marker symbol="+" word="Added" />
            {name !== null && <span>{name}</span>}
            <span className="font-mono text-xs text-muted-foreground">{key}</span>
          </li>
        ))}
        {section.removed.map(({ key, name }, index) => (
          <li key={`-${String(index)}:${key}`} className="flex flex-wrap items-baseline gap-x-2">
            <Marker symbol="−" word="Removed" />
            {name !== null && <span>{name}</span>}
            <span className="font-mono text-xs text-muted-foreground">{key}</span>
          </li>
        ))}
        {section.changed.map(({ key, name, previousName, fields }, index) => {
          const was = previousName ?? key
          const now = name ?? key
          return (
            <li key={`~${String(index)}:${key}`} className="flex flex-wrap items-baseline gap-x-2">
              <Marker symbol="~" word="Changed" />
              {was === now ? (
                name !== null && <span>{name}</span>
              ) : (
                <span>
                  {was} → {now}
                </span>
              )}
              <span className="font-mono text-xs text-muted-foreground">{key}</span>
              {fields.length > 0 && (
                <span className="basis-full pl-5 text-xs text-muted-foreground">
                  Changed: {fields.join(', ')}
                </span>
              )}
            </li>
          )
        })}
      </ul>
    </div>
  )
}

/** The `+`, `−` or `~` of an item; a screen reader reads the word instead. */
function Marker({ symbol, word }: { symbol: string; word: string }) {
  return (
    <>
      <span aria-hidden="true" className="w-3 font-mono text-muted-foreground">
        {symbol}
      </span>
      <span className="sr-only">{word}</span>
    </>
  )
}
