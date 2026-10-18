<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemRepository;
use App\Studio\Domain\Release\GameSystemReleaseRepository;

final readonly class ListMyGameSystemsHandler implements QueryHandler
{
    public function __construct(
        private GameSystemRepository $gameSystems,
        private GameSystemReleaseRepository $releases,
        private LibraryImportStandings $standings,
    ) {
    }

    /**
     * @return list<GameSystemSummary> most recently updated first; library packs are not listed
     */
    public function __invoke(ListMyGameSystems $query): array
    {
        $drafts = array_values(array_filter($this->gameSystems->ofOwner($query->ownerId), static fn (GameSystem $gameSystem): bool => !$gameSystem->isLibraryPack()));

        $changes = $this->standings->changesOfEach($drafts);

        return array_map(function (GameSystem $gameSystem) use ($changes): GameSystemSummary {
            $publication = GameSystemPublication::of($gameSystem, $this->releases);

            return new GameSystemSummary($gameSystem->key(), $gameSystem->name(), $gameSystem->schemaVersion(), $publication->latestReleaseVersion, $publication->hasUnpublishedChanges, $changes[$gameSystem->key()], $gameSystem->updatedAt());
        }, $drafts);
    }
}
