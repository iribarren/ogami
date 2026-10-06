<?php

declare(strict_types=1);

namespace App\Play\Application;

final readonly class SessionView
{
    /**
     * @param list<SceneView> $scenes in number order
     */
    public function __construct(
        public int $number,
        public \DateTimeImmutable $startedAt,
        public array $scenes,
    ) {
    }
}
