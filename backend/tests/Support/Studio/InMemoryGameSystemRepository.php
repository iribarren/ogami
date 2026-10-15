<?php

declare(strict_types=1);

namespace App\Tests\Support\Studio;

use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemKeyTaken;
use App\Studio\Domain\GameSystem\GameSystemRepository;

final class InMemoryGameSystemRepository implements GameSystemRepository
{
    /** @var array<string, GameSystem> by key */
    private array $gameSystems = [];

    /**
     * @throws GameSystemKeyTaken like the database's unique index
     */
    public function add(GameSystem $gameSystem): void
    {
        if (isset($this->gameSystems[$gameSystem->key()])) {
            throw GameSystemKeyTaken::withKey($gameSystem->key());
        }

        $this->gameSystems[$gameSystem->key()] = $gameSystem;
    }

    public function save(GameSystem $gameSystem): void
    {
        if (($this->gameSystems[$gameSystem->key()] ?? null) !== $gameSystem) {
            throw new \LogicException(\sprintf('GameSystem "%s" was not added through this repository.', $gameSystem->key()));
        }
    }

    public function ofKey(string $key): ?GameSystem
    {
        return $this->gameSystems[$key] ?? null;
    }

    public function ofOwner(string $ownerId): array
    {
        $owned = array_values(array_filter($this->gameSystems, static fn (GameSystem $gameSystem): bool => $gameSystem->isOwnedBy($ownerId)));
        usort($owned, static fn (GameSystem $a, GameSystem $b): int => [$b->updatedAt(), $a->key()] <=> [$a->updatedAt(), $b->key()]);

        return $owned;
    }

    public function remove(GameSystem $gameSystem): void
    {
        unset($this->gameSystems[$gameSystem->key()]);
    }
}
