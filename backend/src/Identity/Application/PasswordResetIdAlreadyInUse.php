<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\PasswordResetId;

final class PasswordResetIdAlreadyInUse extends \RuntimeException
{
    public static function for(PasswordResetId $id): self
    {
        return new self(\sprintf('A password reset with id "%s" already exists.', $id->toString()));
    }
}
