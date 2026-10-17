<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Creates a sheet field in the owner's GameSystem draft, or replaces the one with $originalKey
 * (renaming it when $field has another key). $expectedVersion is the draft version the client read.
 */
final readonly class SaveSheetField implements Command
{
    /**
     * @param string|null  $originalKey the key of the field to replace; null creates a new one
     * @param array<mixed> $field       the field as in a release document
     */
    public function __construct(
        public string $ownerId,
        public string $key,
        public int $expectedVersion,
        public ?string $originalKey,
        public array $field,
    ) {
    }
}
