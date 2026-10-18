<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;

/**
 * Lists what the owner's GameSystem draft imported from the library, with each import's status.
 *
 * @implements Query<LibraryImportsView>
 */
final readonly class GetGameSystemLibraryImports implements Query
{
    public function __construct(
        public string $ownerId,
        public string $key,
    ) {
    }
}
