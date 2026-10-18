<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemRepository;

/**
 * Finds a library pack for importing from it. Every game manager reads every pack, so, unlike
 * OwnedGameSystems, the owner does not matter; a GameSystem that has the key is not a pack.
 */
final readonly class LibraryPacks
{
    public function __construct(
        private GameSystemRepository $gameSystems,
    ) {
    }

    /**
     * @throws LibraryPackNotFound when no library pack has the key
     */
    public function get(string $key): GameSystem
    {
        return $this->find($key) ?? throw LibraryPackNotFound::withKey($key);
    }

    public function find(string $key): ?GameSystem
    {
        $pack = $this->gameSystems->ofKey($key);

        return $pack instanceof GameSystem && $pack->isLibraryPack() ? $pack : null;
    }
}
