<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem;

use App\Randomness\Domain\Oracle\LikelihoodOracle;

/**
 * A likelihood oracle of a GameSystem snapshot: its key and name in the GameSystem, and the oracle.
 */
final readonly class SnapshotLikelihoodOracle
{
    public function __construct(
        private string $key,
        private string $name,
        private LikelihoodOracle $oracle,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function oracle(): LikelihoodOracle
    {
        return $this->oracle;
    }
}
