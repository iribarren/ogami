<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\QueryHandler;

final readonly class ListGameSystemsHandler implements QueryHandler
{
    public function __construct(
        private PublishedGameSystemReleases $releases,
    ) {
    }

    /**
     * @return list<GameSystemSummary> ordered by name ignoring case, then key, each with the Flows
     *                                 of its release (none when Play cannot read the release, which
     *                                 is listed as before; creating a campaign with it fails)
     */
    public function __invoke(ListGameSystems $query): array
    {
        return $this->releases->latest();
    }
}
