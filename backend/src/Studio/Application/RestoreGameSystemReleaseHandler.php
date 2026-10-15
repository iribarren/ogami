<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\CommandHandler;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\GameSystemRepository;
use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\GameSystemReleaseNotFound;
use App\Studio\Domain\Release\GameSystemReleaseRepository;

final readonly class RestoreGameSystemReleaseHandler implements CommandHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private GameSystemRepository $gameSystems,
        private GameSystemReleaseRepository $releases,
        private Clock $clock,
    ) {
    }

    /**
     * @throws GameSystemNotFound             when the user has no such GameSystem
     * @throws GameSystemReleaseNotFound      when it has no such release; the draft is unchanged
     * @throws GameSystemModifiedConcurrently when another request saved the draft meanwhile
     */
    public function __invoke(RestoreGameSystemRelease $command): void
    {
        $gameSystem = $this->owned->get($command->ownerId, $command->key);
        $release = $this->releases->get($command->key, $command->version);
        if (!$release instanceof GameSystemRelease) {
            throw GameSystemReleaseNotFound::for($command->key, $command->version);
        }

        $gameSystem->restore($release->content()->toArray(), $this->clock->now());
        $this->gameSystems->save($gameSystem);
    }
}
