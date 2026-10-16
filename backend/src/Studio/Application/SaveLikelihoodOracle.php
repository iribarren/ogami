<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Creates a likelihood oracle in the owner's GameSystem draft, or replaces the one with
 * $originalKey (renaming it when $oracle has another key). $expectedVersion is the draft version
 * the client read.
 */
final readonly class SaveLikelihoodOracle implements Command
{
    /**
     * @param string|null  $originalKey the key of the oracle to replace; null creates a new one
     * @param array<mixed> $oracle      the oracle as in a release document
     */
    public function __construct(
        public string $ownerId,
        public string $key,
        public ?string $originalKey,
        public array $oracle,
        public int $expectedVersion,
    ) {
    }
}
