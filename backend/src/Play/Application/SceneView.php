<?php

declare(strict_types=1);

namespace App\Play\Application;

final readonly class SceneView
{
    public function __construct(
        public int $number,
        public string $title,
        public \DateTimeImmutable $startedAt,
    ) {
    }
}
