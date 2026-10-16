<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\CommandHandler;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\GameSystemRepository;
use App\Studio\Domain\GameSystem\NarrativeItemInUse;
use App\Studio\Domain\GameSystem\NarrativeItemNotFound;
use App\Studio\Domain\Release\InvalidReleaseContent;

/**
 * The command returns nothing, like every command on the bus: the new draft version is read
 * back with GetGameSystemNarrative.
 */
final readonly class DeleteNarrativeItemHandler implements CommandHandler
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
     * @throws NarrativeItemNotFound          when the draft has no such item
     * @throws NarrativeItemInUse             when the draft references the item; nothing changes
     * @throws InvalidReleaseContent          for a schema version 1 draft or a draft that breaks the item rules; nothing changes
     */
    public function __invoke(DeleteNarrativeItem $command): void
    {
        $gameSystem = $this->owned->getAtVersion($command->ownerId, $command->key, $command->expectedVersion);
        $gameSystem->deleteNarrativeItem($command->section, $command->itemKey, $this->clock->now());
        $this->gameSystems->save($gameSystem);
    }
}
