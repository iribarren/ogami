<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\CommandHandler;
use App\Studio\Domain\GameSystem\GameSystemMetadata;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\GameSystemRepository;
use App\Studio\Domain\GameSystem\InvalidGameSystemMetadata;

final readonly class EditGameSystemMetadataHandler implements CommandHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private GameSystemRepository $gameSystems,
        private Clock $clock,
    ) {
    }

    /**
     * @throws GameSystemNotFound             when the user has no such GameSystem
     * @throws InvalidGameSystemMetadata      naming the first offending field; nothing changes
     * @throws GameSystemModifiedConcurrently when another request saved it meanwhile
     */
    public function __invoke(EditGameSystemMetadata $command): void
    {
        $gameSystem = $this->owned->get($command->ownerId, $command->key);
        $gameSystem->editMetadata(GameSystemMetadata::of($command->name, $command->description, $command->credits, $command->theme), $this->clock->now());
        $this->gameSystems->save($gameSystem);
    }
}
