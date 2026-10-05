<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Shared\Application\Bus\QueryHandler;

final readonly class GetGameSystemSnapshotHandler implements QueryHandler
{
    public function __construct(
        private PublishedGameSystemReleases $releases,
    ) {
    }

    /**
     * @throws GameSystemReleaseNotFound
     */
    public function __invoke(GetGameSystemSnapshot $query): GameSystemSnapshot
    {
        return $this->releases->get($query->gameSystemKey, $query->version);
    }
}
