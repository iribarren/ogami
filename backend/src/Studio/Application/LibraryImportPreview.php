<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * What an import would do: the items it copies (the requested ones and what they need), the needed
 * items the draft already has and keeps, the requested items the draft already has (which refuse
 * the import) and, when nothing conflicts, the item rule the result would break.
 */
final readonly class LibraryImportPreview
{
    /**
     * @param list<array{section: string, key: string}> $imports   in pack order
     * @param list<array{section: string, key: string}> $overrides in pack order
     * @param list<array{section: string, key: string}> $conflicts in the order requested
     * @param array{path: string, message: string}|null $error
     */
    public function __construct(
        public array $imports,
        public array $overrides,
        public array $conflicts,
        public ?array $error,
    ) {
    }
}
