<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\InvalidEmail;
use App\Identity\Domain\InvitationMustHaveARole;
use App\Identity\Domain\UserMustHaveARole;

/**
 * Published contract: tells whether an error is the caller's bad input (an email that is not an
 * address, no role, a value that is not a role) rather than a fault. Other contexts' HTTP adapters
 * answer it 422 without importing Identity's Domain; any other error stays a fault.
 */
final readonly class InvalidUserInput
{
    public static function is(\Throwable $error): bool
    {
        return $error instanceof InvalidEmail
            || $error instanceof UserMustHaveARole
            || $error instanceof InvitationMustHaveARole
            || $error instanceof UnknownRole;
    }
}
