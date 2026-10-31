<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * Closes Threads: the open ones among the items most recently picked or created in the current
 * Scene, or, when a fact slot key is given, the Thread that fills that fact slot (nothing when the
 * slot is empty or that Thread is already closed).
 */
final readonly class CloseThreadEffect implements Effect
{
    public function __construct(
        public ?string $factSlot = null,
    ) {
    }
}
