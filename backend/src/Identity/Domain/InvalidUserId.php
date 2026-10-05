<?php

declare(strict_types=1);

namespace App\Identity\Domain;

final class InvalidUserId extends \DomainException
{
    public static function blank(): self
    {
        return new self('A user id must not be blank.');
    }
}
