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

final readonly class CreateGameSystemHandler implements CommandHandler
{
    public function __construct(
        private GameSystemRepository $gameSystems,
        private GameSystemReleaseRepository $releases,
        private Clock $clock,
    ) {
    }

    /**
     * @throws InvalidGameSystemMetadata when the key, name or description is invalid, or a version is given without a source
     * @throws PublishedReleaseNotFound  when the source release does not exist
     * @throws GameSystemKeyTaken        when a GameSystem or any release has the key
     */
    public function __invoke(CreateGameSystem $command): void
    {
        $id = GameSystemId::fromString($command->id);
        $now = $this->clock->now();
        if (null === $command->sourceKey) {
            if (null !== $command->sourceVersion) {
                throw InvalidGameSystemMetadata::at('sourceVersion', 'needs a source key');
            }

            $gameSystem = GameSystem::createBlank($id, $command->key, $command->name, $command->description, $command->ownerId, $now);
        } else {
            $source = null === $command->sourceVersion
                ? $this->releases->latestFor($command->sourceKey)
                : $this->releases->get($command->sourceKey, $command->sourceVersion);
            if (!$source instanceof GameSystemRelease) {
                throw PublishedReleaseNotFound::for($command->sourceKey, $command->sourceVersion);
            }

            $gameSystem = GameSystem::createFromRelease($id, $command->key, $command->name, $command->description, $command->ownerId, $source->content()->toArray(), $now);
        }

        if ($this->releases->latestFor($command->key) instanceof GameSystemRelease) {
            throw GameSystemKeyTaken::withKey($command->key);
        }

        $this->gameSystems->add($gameSystem);
    }
}
