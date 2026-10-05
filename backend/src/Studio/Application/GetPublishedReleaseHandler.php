<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\GameSystemReleaseRepository;

final readonly class GetPublishedReleaseHandler implements QueryHandler
{
    public function __construct(
        private GameSystemReleaseRepository $releases,
    ) {
    }

    /**
     * @throws PublishedReleaseNotFound
     */
    public function __invoke(GetPublishedRelease $query): PublishedReleaseView
    {
        $release = null === $query->version
            ? $this->releases->latestFor($query->gameSystemKey)
            : $this->releases->get($query->gameSystemKey, $query->version);

        if (!$release instanceof GameSystemRelease) {
            throw PublishedReleaseNotFound::for($query->gameSystemKey, $query->version);
        }

        return PublishedReleaseViews::of($release);
    }
}
