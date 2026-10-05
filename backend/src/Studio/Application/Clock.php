<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * Port: tells the current time, so publishing can be tested at a fixed instant.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
