<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Shared\Application\Bus\Command;

/**
 * Lets a disabled user sign in again. Idempotent.
 */
final readonly class EnableUser implements Command
{
    public function __construct(
        public string $userId,
    ) {
    }
}
