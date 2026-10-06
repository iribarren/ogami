<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Clock;

use App\Play\Application\Clock;

/**
 * The real time, in UTC (timestamps are stored without a time zone).
 */
final readonly class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
