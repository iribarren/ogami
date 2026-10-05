<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * Port: turns a plain password into a one-way hash.
 */
interface PasswordHasher
{
    public function hash(string $plainPassword): string;
}
