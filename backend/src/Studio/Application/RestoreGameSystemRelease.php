<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Replaces the draft of the GameSystem with $key, owned by $ownerId, with the content of its release
 * $version. The key and the owner stay.
 */
final readonly class RestoreGameSystemRelease implements Command
{
    public function __construct(
        public string $ownerId,
        public string $key,
        public int $version,
    ) {
    }
}
