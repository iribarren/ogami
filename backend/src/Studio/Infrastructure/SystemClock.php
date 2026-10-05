<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure;

use App\Studio\Application\Clock;

/**
 * The real time, in UTC (publishedAt is stored without a time zone).
 */
final readonly class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
