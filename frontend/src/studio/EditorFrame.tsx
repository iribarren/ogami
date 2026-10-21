import type { ReactNode } from 'react'

import { IconTile } from '@/shared/ui/icon-tile'

import { SectionRail } from './SectionRail'
import type { GameSystemTab } from './gameSystemTabs'
import { useStudioGameSystem } from './useGameSystems'

/**
 * The page around an item editor (oracle table, likelihood oracle, Scene Type): back link, a
 * header with the section's tile, the GameSystem name and kind above the title, and the section
 * rail with the parent section active. The rail waits for the GameSystem; without it the editor
 * still shows, with the key in place of the name.
 */
export function EditorFrame({
  gameSystemKey,
  section,
  kind,
  icon,
  title,
  backLink,
  children,
}: {
  gameSystemKey: string
  section: GameSystemTab
  /** "Oracle table", "Likelihood oracle" or "Scene Type". */
  kind: string
  icon: ReactNode
  title: string
  backLink: ReactNode
  children: ReactNode
}) {
  const gameSystem = useStudioGameSystem(gameSystemKey)

  return (
    <div className="mx-auto w-full max-w-[1232px] space-y-6">
      {backLink}
      <header className="flex flex-wrap items-center gap-4">
        <IconTile size="lg">{icon}</IconTile>
        <div className="min-w-0 flex-1">
          <p className="text-xs text-muted-foreground">
            {gameSystem.data?.name ?? gameSystemKey} · {kind}
          </p>
          <h1 className="font-heading text-[26px] leading-tight font-bold break-words">{title}</h1>
        </div>
      </header>
      <div className="flex flex-wrap gap-7">
        {gameSystem.data && <SectionRail gameSystem={gameSystem.data} active={section} />}
        <div className="min-w-0 flex-[999_1_560px] space-y-6">{children}</div>
      </div>
    </div>
  )
}
