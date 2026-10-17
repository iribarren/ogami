<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\CommandHandler;
use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\GameSystem\GameSystemKeyTaken;
use App\Studio\Domain\GameSystem\GameSystemRepository;
use App\Studio\Domain\GameSystem\InvalidGameSystemMetadata;
use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\GameSystemReleaseRepository;

final readonly class CreateLibraryPackHandler implements CommandHandler
{
    public function __construct(
        private GameSystemRepository $gameSystems,
        private GameSystemReleaseRepository $releases,
        private Clock $clock,
    ) {
    }

    /**
     * @throws InvalidGameSystemMetadata when the key, name or description is invalid
     * @throws GameSystemKeyTaken        when a GameSystem, a library pack or any release has the key
     */
    public function __invoke(CreateLibraryPack $command): void
    {
        $pack = GameSystem::createLibraryPack(GameSystemId::fromString($command->id), $command->key, $command->ownerId, $command->name, $command->description, $this->clock->now());

        if ($this->releases->latestFor($command->key) instanceof GameSystemRelease) {
            throw GameSystemKeyTaken::withKey($command->key);
        }

        $this->gameSystems->add($pack);
    }
}
