<?php

declare(strict_types=1);

namespace App\Shared\Application\Health;

/**
 * Port: tells whether the database answers right now.
 */
interface DatabaseConnectivity
{
    public function isReachable(): bool;
}
