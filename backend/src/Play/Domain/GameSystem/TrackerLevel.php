<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem;

/**
 * A named range of a counter: values up to "upTo" (the last level has none and catches the rest).
 */
final readonly class TrackerLevel
{
    public function __construct(
        public ?int $upTo,
        public string $label,
    ) {
    }
}
