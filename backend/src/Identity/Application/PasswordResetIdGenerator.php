<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\PasswordResetId;

/**
 * Port: hands out a new, unique PasswordResetId.
 */
interface PasswordResetIdGenerator
{
    public function generate(): PasswordResetId;
}
