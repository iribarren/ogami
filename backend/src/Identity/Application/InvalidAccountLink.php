<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * The link of an account email cannot be used. One generic error for a blank, unknown,
 * expired, used or revoked token, so a caller learns nothing about which it was.
 */
final class InvalidAccountLink extends \RuntimeException
{
    public static function create(): self
    {
        return new self('This link is invalid or has expired.');
    }
}
