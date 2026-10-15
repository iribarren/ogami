<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Deletes the owner's GameSystem draft, which must never have been published.
 */
final readonly class DiscardGameSystem implements Command
{
    public function __construct(
        public string $ownerId,
        public string $key,
    ) {
    }
}
