<?php

declare(strict_types=1);

namespace App\Identity\Application;

final class UserNotFound extends \RuntimeException
{
    public static function withId(string $id): self
    {
        return new self(\sprintf('No user with id "%s".', $id));
    }

    public static function withEmail(string $email): self
    {
        return new self(\sprintf('No user with email "%s".', $email));
    }
}
