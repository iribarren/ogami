<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Application\Clock;

/**
 * The real time, in UTC (createdAt is stored without a time zone).
 */
final readonly class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
