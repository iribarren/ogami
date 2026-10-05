<?php

declare(strict_types=1);

namespace App\Identity\Domain;

final class InvalidEmail extends \DomainException
{
    public static function malformed(string $address): self
    {
        return new self(\sprintf('"%s" is not a valid email address.', $address));
    }
}
