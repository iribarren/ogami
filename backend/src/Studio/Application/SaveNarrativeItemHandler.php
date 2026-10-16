<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\CommandHandler;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\GameSystemRepository;
use App\Studio\Domain\GameSystem\NarrativeItemInUse;
use App\Studio\Domain\GameSystem\NarrativeItemNotFound;
use App\Studio\Domain\GameSystem\NarrativeKeyTaken;
use App\Studio\Domain\Release\InvalidReleaseContent;

/**
 * The command returns nothing, like every command on the bus: the new draft version is read
 * back with GetGameSystemNarrative.
 */
final readonly class SaveNarrativeItemHandler implements CommandHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private GameSystemRepository $gameSystems,
        private Clock $clock,
    ) {
    }

    /**
     * @throws GameSystemNotFound             when the user has no such GameSystem
     * @throws GameSystemModifiedConcurrently when $expectedVersion is not the draft's version, or another request saved it meanwhile
     * @throws NarrativeKeyTaken              when the new key names another item of the section
     * @throws NarrativeItemNotFound          when $originalKey names nothing
     * @throws NarrativeItemInUse             when a rename is referenced by the draft
     * @throws InvalidReleaseContent          naming the offending path, also for a schema version 1 draft; nothing changes
     */
    public function __invoke(SaveNarrativeItem $command): void
    {
        $gameSystem = $this->owned->getAtVersion($command->ownerId, $command->key, $command->expectedVersion);
        $gameSystem->saveNarrativeItem($command->section, $command->originalKey, $command->item, $this->clock->now());
        $this->gameSystems->save($gameSystem);
    }
}
