<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\CommandHandler;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\GameSystemRepository;
use App\Studio\Domain\GameSystem\LibraryItemKeyTaken;
use App\Studio\Domain\GameSystem\LibraryItemNotFound;
use App\Studio\Domain\GameSystem\LibraryItemRef;
use App\Studio\Domain\GameSystem\LibraryPackRefuses;
use App\Studio\Domain\Release\InvalidReleaseContent;

/**
 * The command returns nothing, like every command on the bus: the imports and the new draft
 * version are read back with GetGameSystemLibraryImports and GetGameSystem.
 */
final readonly class ImportLibraryItemsHandler implements CommandHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private LibraryPacks $packs,
        private GameSystemRepository $gameSystems,
        private Clock $clock,
    ) {
    }

    /**
     * @throws InvalidReleaseContent          when the items are malformed (naming the path), the draft is schema version 1 or the result would break the item rules; nothing changes
     * @throws GameSystemNotFound             when the user has no such GameSystem
     * @throws GameSystemModifiedConcurrently when $expectedVersion is not the draft's version, or another request saved it meanwhile
     * @throws LibraryPackNotFound            when no library pack has the key
     * @throws LibraryPackRefuses             when the target is a library pack
     * @throws LibraryItemNotFound            when the pack has none of an item
     * @throws LibraryItemKeyTaken            when the draft already has a requested item; lists every one
     */
    public function __invoke(ImportLibraryItems $command): void
    {
        $items = LibraryItemRef::listFromArray($command->items);
        $gameSystem = $this->owned->getAtVersion($command->ownerId, $command->key, $command->expectedVersion);
        $gameSystem->importFromLibrary($this->packs->get($command->pack), $items, $this->clock->now());
        $this->gameSystems->save($gameSystem);
    }
}
