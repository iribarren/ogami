<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\GameSystemReleaseRepository;
use App\Studio\Domain\Release\ReleaseId;

final readonly class GetPublishedReleaseByIdHandler implements QueryHandler
{
    public function __construct(
        private GameSystemReleaseRepository $releases,
    ) {
    }

    public function __invoke(GetPublishedReleaseById $query): ?PublishedReleaseView
    {
        $release = $this->releases->ofId(ReleaseId::fromString($query->releaseId));

        return $release instanceof GameSystemRelease ? PublishedReleaseViews::of($release) : null;
    }
}
