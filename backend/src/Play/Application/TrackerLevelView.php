<?php

declare(strict_types=1);

namespace App\Play\Application;

final readonly class TrackerLevelView
{
    public function __construct(
        public ?int $upTo,
        public string $label,
    ) {
    }
}
