<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem;

/**
 * A Fact slot the release declares (filled by feature 8b).
 */
final readonly class FactSlot
{
    public function __construct(
        public string $key,
        public string $label,
        public FactSlotType $type,
    ) {
    }
}
