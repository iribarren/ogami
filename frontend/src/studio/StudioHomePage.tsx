import { Link } from '@tanstack/react-router'

import { Badge } from '@/shared/ui/badge'
import { IconTile } from '@/shared/ui/icon-tile'

import { LibraryChangesBadge } from './LibraryChangesBadge'
import { LibraryPacks } from './library/LibraryPacks'
import { NewGameSystemDialog } from './NewGameSystemDialog'
import { useMyGameSystems, type GameSystemSummary } from './useGameSystems'

/** `/studio`: my GameSystems, a way to start a new one, and the library of packs. */
export function StudioHomePage() {
  const gameSystems = useMyGameSystems()

  return (
    <div className="space-y-8">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div className="space-y-1">
          <h1 className="font-heading text-page-title">Studio</h1>
          <p className="text-muted-foreground">Author GameSystems and publish them to Play.</p>
        </div>
        <NewGameSystemDialog />
      </header>
      <section aria-labelledby="my-game-systems" className="space-y-3">
        <div className="flex items-center gap-2">
          <h2 id="my-game-systems" className="section-title">
            Your GameSystems
          </h2>
          {gameSystems.data && <span className="count-pill">{gameSystems.data.length}</span>}
        </div>
        <GameSystems />
      </section>
      <LibraryPacks />
    </div>
  )
}

function GameSystems() {
  const gameSystems = useMyGameSystems()
  if (gameSystems.isPending) {
    return <p className="text-muted-foreground">Loading your GameSystems…</p>
  }
  if (gameSystems.isError) {
    return (
      <p role="alert" className="text-sm text-destructive">
        {gameSystems.error.message}
      </p>
    )
  }
  if (gameSystems.data.length === 0) {
    return (
      <p className="text-muted-foreground">
        You have no GameSystems yet. Create one to start authoring.
      </p>
    )
  }
  return (
    <ul className="grid grid-cols-[repeat(auto-fill,minmax(min(320px,100%),1fr))] gap-4">
      {gameSystems.data.map((gameSystem) => (
        <li key={gameSystem.key}>
          <GameSystemCard gameSystem={gameSystem} />
        </li>
      ))}
    </ul>
  )
}

function GameSystemCard({ gameSystem }: { gameSystem: GameSystemSummary }) {
  return (
    <div className="flex h-full gap-3 panel p-4">
      <IconTile size="lg">
        <span className="font-heading text-xl font-bold">
          {gameSystem.name.trim().charAt(0).toUpperCase() || '?'}
        </span>
      </IconTile>
      <div className="min-w-0 flex-1 space-y-1.5">
        <h3 className="font-heading text-panel-title break-words">
          <Link
            to="/studio/game-systems/$key"
            params={{ key: gameSystem.key }}
            className="underline-offset-4 hover:underline"
          >
            {gameSystem.name}
          </Link>
        </h3>
        <p className="list-row-key break-all">{gameSystem.key}</p>
        <div className="flex flex-wrap items-center gap-2 pt-1 text-sm">
          {gameSystem.latestReleaseVersion === null ? (
            <Badge variant="outline">Never published</Badge>
          ) : (
            <Badge variant="secondary">
              Latest release v{String(gameSystem.latestReleaseVersion)}
            </Badge>
          )}
          {gameSystem.hasUnpublishedChanges && <Badge variant="warning">Unpublished changes</Badge>}
          <LibraryChangesBadge count={gameSystem.libraryChanges} />
        </div>
      </div>
    </div>
  )
}
