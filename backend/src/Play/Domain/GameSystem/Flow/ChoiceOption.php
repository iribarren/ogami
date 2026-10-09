<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

final readonly class ChoiceOption
{
    public function __construct(
        public string $key,
        public string $label,
        public Outcome $outcome,
    ) {
    }
}
