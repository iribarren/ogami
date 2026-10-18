<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\CommandHandler;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\GameSystemRepository;
use App\Studio\Domain\GameSystem\LibraryImportNotFound;
use App\Studio\Domain\GameSystem\LibraryPackRefuses;

final readonly class DetachLibraryImportHandler implements CommandHandler
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
     * @throws LibraryPackRefuses             when the target is a library pack
     * @throws LibraryImportNotFound          when the draft did not import the item
     */
    public function __invoke(DetachLibraryImport $command): void
    {
        $gameSystem = $this->owned->getAtVersion($command->ownerId, $command->key, $command->expectedVersion);
        $gameSystem->detachLibraryImport($command->section, $command->itemKey, $this->clock->now());
        $this->gameSystems->save($gameSystem);
    }
}
