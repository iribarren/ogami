import { Link } from '@tanstack/react-router'

import { Badge } from '@/shared/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card'

import { NewGameSystemDialog } from './NewGameSystemDialog'
import { useMyGameSystems, type GameSystemSummary } from './useGameSystems'

/** `/studio`: my GameSystems and a way to start a new one. */
export function StudioHomePage() {
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
        <h2 id="my-game-systems" className="font-heading text-xl font-semibold">
          Your GameSystems
        </h2>
        <GameSystems />
      </section>
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
    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
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
    <Card>
      <CardHeader>
        <CardTitle className="break-words">
          <Link
            to="/studio/game-systems/$key"
            params={{ key: gameSystem.key }}
            className="underline-offset-4 hover:underline"
          >
            {gameSystem.name}
          </Link>
        </CardTitle>
        <p className="font-mono text-sm break-all text-muted-foreground">{gameSystem.key}</p>
      </CardHeader>
      <CardContent className="flex flex-wrap items-center gap-2 text-sm">
        <span>
          {gameSystem.latestReleaseVersion === null
            ? 'Never published'
            : `Latest release v${String(gameSystem.latestReleaseVersion)}`}
        </span>
        {gameSystem.hasUnpublishedChanges && <Badge variant="warning">Unpublished changes</Badge>}
      </CardContent>
    </Card>
  )
}
