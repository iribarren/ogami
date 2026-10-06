<?php

declare(strict_types=1);

namespace App\Tests\Support\Studio;

use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\GameSystemReleaseRepository;
use App\Studio\Domain\Release\ReleaseId;

/**
 * Wraps a repository and lets another publish happen right before or right after each add(),
 * as a concurrent process would. The hooks receive the wrapped repository and the release being added.
 */
final readonly class InterleavingGameSystemReleaseRepository implements GameSystemReleaseRepository
{
    /**
     * @param (\Closure(GameSystemReleaseRepository, GameSystemRelease): void)|null $beforeAdd
     * @param (\Closure(GameSystemReleaseRepository, GameSystemRelease): void)|null $afterAdd
     */
    public function __construct(
        private GameSystemReleaseRepository $inner,
        private ?\Closure $beforeAdd = null,
        private ?\Closure $afterAdd = null,
    ) {
    }

    public function add(GameSystemRelease $release): void
    {
        if ($this->beforeAdd instanceof \Closure) {
            ($this->beforeAdd)($this->inner, $release);
        }

        $this->inner->add($release);

        if ($this->afterAdd instanceof \Closure) {
            ($this->afterAdd)($this->inner, $release);
        }
    }

    public function latestFor(string $gameSystemKey): ?GameSystemRelease
    {
        return $this->inner->latestFor($gameSystemKey);
    }

    public function latestOfEachKey(): array
    {
        return $this->inner->latestOfEachKey();
    }

    public function get(string $gameSystemKey, int $version): ?GameSystemRelease
    {
        return $this->inner->get($gameSystemKey, $version);
    }

    public function ofId(ReleaseId $id): ?GameSystemRelease
    {
        return $this->inner->ofId($id);
    }
}
