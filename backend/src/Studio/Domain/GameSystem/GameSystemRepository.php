<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * Port: where GameSystem drafts are kept. Releases are kept apart (GameSystemReleaseRepository).
 */
interface GameSystemRepository
{
    /**
     * @throws GameSystemKeyTaken when another GameSystem has the key
     */
    public function add(GameSystem $gameSystem): void;

    /**
     * Keeps the changes of a GameSystem this repository added or returned.
     *
     * @throws GameSystemModifiedConcurrently when another request saved it since it was loaded
     */
    public function save(GameSystem $gameSystem): void;

    public function ofKey(string $key): ?GameSystem;

    /**
     * @return list<GameSystem> the owner's GameSystems, the most recently updated first
     */
    public function ofOwner(string $ownerId): array;

    public function remove(GameSystem $gameSystem): void;
}
