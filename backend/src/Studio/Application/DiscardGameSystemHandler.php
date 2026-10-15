<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\CommandHandler;
use App\Studio\Domain\GameSystem\GameSystemHasReleases;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\GameSystemRepository;
use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\GameSystemReleaseRepository;

final readonly class DiscardGameSystemHandler implements CommandHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private GameSystemRepository $gameSystems,
        private GameSystemReleaseRepository $releases,
    ) {
    }

    /**
     * @throws GameSystemNotFound    when the user has no such GameSystem
     * @throws GameSystemHasReleases when it was published: campaigns may be pinned to it
     */
    public function __invoke(DiscardGameSystem $command): void
    {
        $gameSystem = $this->owned->get($command->ownerId, $command->key);
        if ($this->releases->latestFor($command->key) instanceof GameSystemRelease) {
            throw GameSystemHasReleases::withKey($command->key);
        }

        $this->gameSystems->remove($gameSystem);
    }
}
