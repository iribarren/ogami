<?php

declare(strict_types=1);

namespace App\Tests\Support\Identity;

use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * Records calls; its "hash" is "hashed:dummy" whatever the input.
 */
final class SpyPasswordHasher implements PasswordHasherInterface
{
    public int $hashed = 0;

    /** @var list<array{string, string}> */
    public array $verified = [];

    public function hash(#[\SensitiveParameter] string $plainPassword): string
    {
        ++$this->hashed;

        return 'hashed:dummy';
    }

    public function verify(string $hashedPassword, #[\SensitiveParameter] string $plainPassword): bool
    {
        $this->verified[] = [$hashedPassword, $plainPassword];

        return false;
    }

    public function needsRehash(string $hashedPassword): bool
    {
        return false;
    }
}
