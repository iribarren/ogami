<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\UserId;

/**
 * A disabled user cannot be sent a password reset: the owner enables the account first.
 */
final class UserDisabled extends \RuntimeException
{
    public static function withId(UserId $id): self
    {
        return new self(\sprintf('The user "%s" is disabled.', $id->toString()));
    }
}
