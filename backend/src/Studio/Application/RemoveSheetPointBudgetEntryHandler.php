<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\CommandHandler;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\GameSystemRepository;
use App\Studio\Domain\GameSystem\SheetPointBudgetNotFound;
use App\Studio\Domain\Release\InvalidReleaseContent;

/**
 * The command returns nothing, like every command on the bus: the new draft version is read
 * back with GetGameSystemSheet.
 */
final readonly class RemoveSheetPointBudgetEntryHandler implements CommandHandler
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
     * @throws SheetPointBudgetNotFound       when the sheet has no budget with this key
     * @throws InvalidReleaseContent          naming the offending path, also for a schema version 1 draft; nothing changes
     */
    public function __invoke(RemoveSheetPointBudgetEntry $command): void
    {
        $gameSystem = $this->owned->getAtVersion($command->ownerId, $command->key, $command->expectedVersion);
        $gameSystem->removeSheetPointBudgetEntry($command->budgetKey, $this->clock->now());
        $this->gameSystems->save($gameSystem);
    }
}
