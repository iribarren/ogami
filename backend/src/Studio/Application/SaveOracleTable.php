<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Creates an oracle table in the owner's GameSystem draft, or replaces the one with
 * $originalKey (renaming it when $table has another key). $expectedVersion is the draft version
 * the client read.
 */
final readonly class SaveOracleTable implements Command
{
    /**
     * @param string|null  $originalKey the key of the table to replace; null creates a new one
     * @param array<mixed> $table       the table as in a release document
     */
    public function __construct(
        public string $ownerId,
        public string $key,
        public ?string $originalKey,
        public array $table,
        public int $expectedVersion,
    ) {
    }
}
