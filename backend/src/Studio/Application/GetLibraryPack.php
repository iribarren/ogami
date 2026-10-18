<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;

/**
 * The catalog of a library pack of any owner, for $userId, who sees whether it is theirs (ADR 0033).
 *
 * @implements Query<LibraryPackCatalog>
 */
final readonly class GetLibraryPack implements Query
{
    public function __construct(
        public string $userId,
        public string $key,
    ) {
    }
}
