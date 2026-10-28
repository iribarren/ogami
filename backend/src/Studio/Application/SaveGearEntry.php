<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Puts the gear entry with this key in the catalog of the owner's GameSystem draft (ADR 0045):
 * replaces it in place, else appends it. $expectedVersion is the draft version the client read.
 */
final readonly class SaveGearEntry implements Command
{
    /**
     * @param array<array-key, mixed> $entry the entry as in a release document ("name", "cost", "slots", "category", "description"; the unset ones left out), without its key. Not typed further: a malformed value is refused with its path
     */
    public function __construct(
        public string $ownerId,
        public string $key,
        public int $expectedVersion,
        public string $gearKey,
        public array $entry,
    ) {
    }
}
