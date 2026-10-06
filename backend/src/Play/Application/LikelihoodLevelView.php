<?php

declare(strict_types=1);

namespace App\Play\Application;

final readonly class LikelihoodLevelView
{
    public function __construct(
        public string $key,
        public string $label,
    ) {
    }
}
