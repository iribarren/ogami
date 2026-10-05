<?php

declare(strict_types=1);

namespace App\Tests\Support\Identity;

use App\Identity\Application\PasswordHasher;

/**
 * Test double with a visible, deterministic "hash".
 */
final class FakePasswordHasher implements PasswordHasher
{
    public function hash(string $plainPassword): string
    {
        return 'hashed:'.$plainPassword;
    }
}
