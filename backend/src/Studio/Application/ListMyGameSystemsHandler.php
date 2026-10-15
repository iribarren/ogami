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
    ) {
    }

    /**
     * @return list<GameSystemSummary> most recently updated first
     */
    public function __invoke(ListMyGameSystems $query): array
    {
        return array_map(function (GameSystem $gameSystem): GameSystemSummary {
            $publication = GameSystemPublication::of($gameSystem, $this->releases);

            return new GameSystemSummary($gameSystem->key(), $gameSystem->name(), $gameSystem->schemaVersion(), $publication->latestReleaseVersion, $publication->hasUnpublishedChanges, $gameSystem->updatedAt());
        }, $this->gameSystems->ofOwner($query->ownerId));
    }
}
