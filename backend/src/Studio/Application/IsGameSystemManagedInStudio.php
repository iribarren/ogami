<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;

/**
 * Asks whether a Studio GameSystem owns the GameSystem key, so only Studio publishes its releases (ADR 0028).
 *
 * @implements Query<bool>
 */
final readonly class IsGameSystemManagedInStudio implements Query
{
    public function __construct(
        public string $gameSystemKey,
    ) {
    }
}
