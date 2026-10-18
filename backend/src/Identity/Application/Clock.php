<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * Port: tells the current time, so account dates can be tested at a fixed instant.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
