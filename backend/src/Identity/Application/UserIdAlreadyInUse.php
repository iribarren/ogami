<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\UserId;

final class UserIdAlreadyInUse extends \RuntimeException
{
    public static function for(UserId $id): self
    {
        return new self(\sprintf('A user with id "%s" already exists.', $id->toString()));
    }
}
