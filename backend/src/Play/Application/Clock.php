<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * Port: tells the current time, so Play can be tested at fixed instants.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
