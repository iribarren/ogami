<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * The rule for a password chosen through an account link (accepting an invitation,
 * resetting a password): 8 to 4,096 characters. The upper bound keeps hashing cheap.
 */
final readonly class PasswordPolicy
{
    public const int MIN_LENGTH = 8;
    public const int MAX_LENGTH = 4096;

    /**
     * @throws WeakPassword
     */
    public static function assertAcceptable(#[\SensitiveParameter] string $plainPassword): void
    {
        $length = mb_strlen($plainPassword, 'UTF-8');
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw WeakPassword::outsideLength(self::MIN_LENGTH, self::MAX_LENGTH);
        }
    }
}
