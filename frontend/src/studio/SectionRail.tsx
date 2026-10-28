import { Link } from '@tanstack/react-router'
import {
  Backpack,
  BookOpen,
  Clapperboard,
  Eye,
  HeartPulse,
  Library,
  ListTree,
  Package,
  Route,
  ShieldCheck,
  UserRound,
} from 'lucide-react'
import type { ReactNode } from 'react'

import { cn } from '@/shared/lib/utils'
import { DieShape } from '@/shared/ui/die-shape'
import { IconTile } from '@/shared/ui/icon-tile'

import type { GameSystemTab } from './gameSystemTabs'
import { useGameSystemValidation, type GameSystem } from './useGameSystems'

interface Section {
  tab: GameSystemTab
  label: string
  icon: ReactNode
  /** Sections a library pack has; the others need releases, flows, a sheet or checks. */
  pack: boolean
  /** The `sectionCounts` names whose sum the pill shows; none present, no pill. */
  counts?: string[]
}

const author: Section[] = [
  { tab: 'overview', label: 'Overview', icon: <BookOpen />, pack: true },
  {
    tab: 'oracles',
    label: 'Oracles',
    icon: <Eye />,
    pack: true,
    counts: ['oracleTables', 'likelihoodOracles'],
  },
  {
    tab: 'narrative',
    label: 'Trackers & tags',
    icon: <HeartPulse />,
    pack: true,
    counts: ['trackers', 'tags', 'factSlots'],
  },
  {
    tab: 'sceneTypes',
    label: 'Scene Types',
    icon: <Clapperboard />,
    pack: true,
    counts: ['sceneTypes'],
  },
  { tab: 'flows', label: 'Flows', icon: <Route />, pack: false, counts: ['flows'] },
  { tab: 'sheet', label: 'Sheet', icon: <UserRound />, pack: false, counts: ['sheetFields'] },
  { tab: 'gear', label: 'Gear', icon: <Backpack />, pack: false },
  {
    tab: 'checks',
    label: 'Checks',
    icon: <DieShape sides={20} inner className="text-current" />,
    pack: false,
    counts: ['checks'],
  },
  { tab: 'library', label: 'Library', icon: <Library />, pack: false },
]

const ship: Section[] = [
  { tab: 'validation', label: 'Validation', icon: <ShieldCheck />, pack: true },
  { tab: 'releases', label: 'Releases', icon: <Package />, pack: false },
  { tab: 'sections', label: 'Sections', icon: <ListTree />, pack: false },
]

/** The text of a section's count pill, or `undefined` when the page has no count for it. */
function pillText(
  section: Section,
  gameSystem: GameSystem,
  validationErrors: number,
): string | undefined {
  if (section.tab === 'validation') {
    return validationErrors > 0 ? String(validationErrors) : undefined
  }
  if (section.tab === 'releases') {
    return gameSystem.latestReleaseVersion === null
      ? undefined
      : `v${String(gameSystem.latestReleaseVersion)}`
  }
  const present = (section.counts ?? []).filter((name) => name in gameSystem.sectionCounts)
  return present.length === 0
    ? undefined
    : String(present.reduce((sum, name) => sum + (gameSystem.sectionCounts[name] ?? 0), 0))
}

/**
 * The section rail of a GameSystem: Author and Ship groups of links to `?tab=…`. A count pill is
 * described to assistive technology instead of being part of the link name, so names stay as
 * they were. A library pack lists only the sections it has.
 */
export function SectionRail({
  gameSystem,
  active,
  onNavigate,
}: {
  gameSystem: GameSystem
  active: GameSystemTab
  onNavigate?: (tab: GameSystemTab) => void
}) {
  const pack = gameSystem.kind === 'library-pack'
  // The error count of the Validation item; no pill until the validation is read or when it fails.
  const validationErrors = useGameSystemValidation(gameSystem.key).data?.errors.length ?? 0
  const groups = [
    { name: 'Author', sections: author },
    { name: 'Ship', sections: ship },
  ]

  return (
    <nav
      aria-label="GameSystem page"
      className="flex flex-[1_1_210px] flex-col gap-4 max-md:max-w-none max-md:flex-none max-md:flex-row max-md:overflow-x-auto md:max-w-[230px]"
    >
      {groups.map(({ name, sections }) => {
        const shown = sections.filter((section) => section.pack || !pack)
        return (
          <div
            key={name}
            role="group"
            aria-labelledby={`rail-group-${name}`}
            className="space-y-1 max-md:flex max-md:items-center max-md:gap-1 max-md:space-y-0"
          >
            <p id={`rail-group-${name}`} className="px-2 pb-1 group-label max-md:pb-0">
              {name}
            </p>
            {shown.map((section) => {
              const pill = pillText(section, gameSystem, validationErrors)
              const pillId = `rail-count-${section.tab}`
              return (
                <Link
                  key={section.tab}
                  to="/studio/game-systems/$key"
                  params={{ key: gameSystem.key }}
                  search={{ tab: section.tab }}
                  // Router state props win over element props, so the section the page shows
                  // (Overview with no or an unknown tab, the parent of an editor) is passed here.
                  inactiveProps={active === section.tab ? { 'aria-current': 'page' } : {}}
                  aria-describedby={pill === undefined ? undefined : pillId}
                  onClick={() => onNavigate?.(section.tab)}
                  className="flex h-[38px] items-center gap-2 rounded-[9px] pr-2 pl-1.5 text-sm font-medium whitespace-nowrap outline-none hover:bg-secondary focus-visible:ring-3 focus-visible:ring-ring/50 aria-[current=page]:bg-[color-mix(in_oklch,var(--primary)_10%,transparent)] aria-[current=page]:shadow-[inset_2px_0_0_var(--primary)]"
                >
                  <IconTile size="rail">{section.icon}</IconTile>
                  <span className="flex-1">{section.label}</span>
                  {pill !== undefined && (
                    <span
                      id={pillId}
                      aria-hidden="true"
                      className={cn(
                        'count-pill',
                        section.tab === 'validation' && 'count-pill-error',
                      )}
                    >
                      {pill}
                    </span>
                  )}
                </Link>
              )
            })}
          </div>
        )
      })}
    </nav>
  )
}
