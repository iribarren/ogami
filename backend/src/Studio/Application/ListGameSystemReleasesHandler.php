<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\Release\GameSystemReleaseRepository;

final readonly class ListGameSystemReleasesHandler implements QueryHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private GameSystemReleaseRepository $releases,
    ) {
    }

    /**
     * @return list<GameSystemReleaseSummary>
     *
     * @throws GameSystemNotFound when the user has no such GameSystem
     */
    public function __invoke(ListGameSystemReleases $query): array
    {
        $this->owned->get($query->ownerId, $query->key);

        return array_map(
            GameSystemReleaseSummary::of(...),
            $this->releases->allFor($query->key),
        );
    }
}
