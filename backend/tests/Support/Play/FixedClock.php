<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Play\Application\Clock;

/**
 * A clock that always tells the time it was given, until moved.
 */
final class FixedClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(string $now = '2026-10-05T10:00:00+00:00')
    {
        $this->now = new \DateTimeImmutable($now);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function moveTo(string $now): void
    {
        $this->now = new \DateTimeImmutable($now);
    }
}
