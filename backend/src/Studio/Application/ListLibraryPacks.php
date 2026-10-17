<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;

/**
 * Lists every library pack of any owner for $userId, who sees which ones are theirs: theirs first,
 * then by name (ADR 0033).
 *
 * @implements Query<list<LibraryPackSummary>>
 */
final readonly class ListLibraryPacks implements Query
{
    public function __construct(
        public string $userId,
    ) {
    }
}
