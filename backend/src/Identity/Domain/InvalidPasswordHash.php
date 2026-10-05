<?php

declare(strict_types=1);

namespace App\Identity\Domain;

final class InvalidPasswordHash extends \DomainException
{
    public static function blank(): self
    {
        return new self('A user must have a password hash.');
    }
}
