<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;

/**
 * Shows what importing $items of the library pack $pack would do to the owner's GameSystem draft,
 * without changing it. $items is the untrusted request list of {section, key}.
 *
 * @implements Query<LibraryImportPreview>
 */
final readonly class PreviewLibraryImport implements Query
{
    public function __construct(
        public string $ownerId,
        public string $key,
        public string $pack,
        public mixed $items,
    ) {
    }
}
