<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\GameSystemRepository;

/**
 * Finds a user's own GameSystem draft. Another owner's behaves as missing, so a key's owner is
 * never revealed (ADR 0028).
 */
final readonly class OwnedGameSystems
{
    public function __construct(
        private GameSystemRepository $gameSystems,
    ) {
    }

    /**
     * @throws GameSystemNotFound when no GameSystem has the key or it belongs to someone else
     */
    public function get(string $ownerId, string $key): GameSystem
    {
        $gameSystem = $this->gameSystems->ofKey($key);
        if (!$gameSystem instanceof GameSystem || !$gameSystem->isOwnedBy($ownerId)) {
            throw GameSystemNotFound::withKey($key);
        }

        return $gameSystem;
    }
}
