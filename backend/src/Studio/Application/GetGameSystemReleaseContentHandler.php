<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\GameSystemReleaseNotFound;
use App\Studio\Domain\Release\GameSystemReleaseRepository;

final readonly class GetGameSystemReleaseContentHandler implements QueryHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private GameSystemReleaseRepository $releases,
        private ReleaseCardImagePins $pins,
    ) {
    }

    /**
     * @throws GameSystemNotFound        when the user has no such GameSystem
     * @throws GameSystemReleaseNotFound when it has no such release
     */
    public function __invoke(GetGameSystemReleaseContent $query): GameSystemReleaseContentView
    {
        $this->owned->get($query->ownerId, $query->key);
        $release = $this->releases->get($query->key, $query->version);
        if (!$release instanceof GameSystemRelease) {
            throw GameSystemReleaseNotFound::for($query->key, $query->version);
        }

        return new GameSystemReleaseContentView(
            $release->gameSystemKey(),
            $release->version(),
            $release->publishedAt(),
            $release->content()->toArray(),
            $this->pins->pinsOf($release->id()),
        );
    }
}
