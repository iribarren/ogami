<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\CommandHandler;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\GameSystemRepository;
use App\Studio\Domain\GameSystem\LibraryImportNotFound;
use App\Studio\Domain\GameSystem\LibraryItemRef;
use App\Studio\Domain\GameSystem\LibraryItemRemoved;
use App\Studio\Domain\GameSystem\LibraryPackRefuses;
use App\Studio\Domain\Release\InvalidReleaseContent;

final readonly class UpdateLibraryImportHandler implements CommandHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private LibraryPacks $packs,
        private GameSystemRepository $gameSystems,
        private Clock $clock,
    ) {
    }

    /**
     * @throws GameSystemNotFound             when the user has no such GameSystem
     * @throws GameSystemModifiedConcurrently when $expectedVersion is not the draft's version, or another request saved it meanwhile
     * @throws LibraryPackRefuses             when the target is a library pack
     * @throws LibraryImportNotFound          when the draft did not import the item
     * @throws LibraryItemRemoved             when the pack, or its item, is gone; nothing changes
     * @throws InvalidReleaseContent          for a schema version 1 draft or a result that breaks the item rules; nothing changes
     */
    public function __invoke(UpdateLibraryImport $command): void
    {
        $gameSystem = $this->owned->getAtVersion($command->ownerId, $command->key, $command->expectedVersion);
        $ref = new LibraryItemRef($command->section, $command->itemKey);
        foreach ($gameSystem->libraryImports() as $import) {
            if ($import->ref()->is($ref)) {
                $pack = $this->packs->find($import->pack) ?? throw LibraryItemRemoved::from($ref, $import->pack);
                $gameSystem->updateFromLibrary($pack, $command->section, $command->itemKey, $this->clock->now());
                $this->gameSystems->save($gameSystem);

                return;
            }
        }

        throw LibraryImportNotFound::for($ref);
    }
}
