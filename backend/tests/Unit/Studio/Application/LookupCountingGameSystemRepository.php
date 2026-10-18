<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Application;

use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemRepository;

/**
 * Wraps a repository and counts how often one key is looked up with ofKey.
 */
final class LookupCountingGameSystemRepository implements GameSystemRepository
{
    public int $lookups = 0;

    public function __construct(
        private readonly GameSystemRepository $inner,
        private readonly string $countedKey,
    ) {
    }

    public function add(GameSystem $gameSystem): void
    {
        $this->inner->add($gameSystem);
    }

    public function save(GameSystem $gameSystem): void
    {
        $this->inner->save($gameSystem);
    }

    public function ofKey(string $key): ?GameSystem
    {
        if ($this->countedKey === $key) {
            ++$this->lookups;
        }

        return $this->inner->ofKey($key);
    }

    public function ofOwner(string $ownerId): array
    {
        return $this->inner->ofOwner($ownerId);
    }

    public function libraryPacks(): array
    {
        return $this->inner->libraryPacks();
    }

    public function remove(GameSystem $gameSystem): void
    {
        $this->inner->remove($gameSystem);
    }
}
