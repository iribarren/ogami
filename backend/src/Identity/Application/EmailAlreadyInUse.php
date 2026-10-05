<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Email;

final class EmailAlreadyInUse extends \RuntimeException
{
    public static function for(Email $email): self
    {
        return new self(\sprintf('A user with email "%s" already exists.', $email->toString()));
    }
}
