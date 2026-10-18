<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * What the owner's GameSystem draft imported from the library, with the draft version a client
 * sends back as the expected version of its next write.
 */
final readonly class LibraryImportsView
{
    /**
     * @param list<LibraryImportView> $imports in the order imported
     */
    public function __construct(
        public int $version,
        public array $imports,
    ) {
    }
}
