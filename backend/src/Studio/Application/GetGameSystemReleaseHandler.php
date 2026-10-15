<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\GameSystemReleaseNotFound;
use App\Studio\Domain\Release\GameSystemReleaseRepository;

final readonly class GetGameSystemReleaseHandler implements QueryHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private GameSystemReleaseRepository $releases,
    ) {
    }

    /**
     * @throws GameSystemNotFound        when the user has no such GameSystem
     * @throws GameSystemReleaseNotFound when it has no such release
     */
    public function __invoke(GetGameSystemRelease $query): GameSystemReleaseView
    {
        $this->owned->get($query->ownerId, $query->key);
        $release = $this->releases->get($query->key, $query->version);
        if (!$release instanceof GameSystemRelease) {
            throw GameSystemReleaseNotFound::for($query->key, $query->version);
        }

        $document = $release->content()->toArray();
        $gameSystem = $document['gameSystem'];
        \assert(\is_array($gameSystem) && \is_string($gameSystem['name']));

        return new GameSystemReleaseView(
            GameSystemReleaseSummary::of($release),
            $gameSystem['name'],
            SectionCounts::of($document, $release->schemaVersion()),
        );
    }
}
