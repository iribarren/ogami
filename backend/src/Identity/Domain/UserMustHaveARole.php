<?php

declare(strict_types=1);

namespace App\Identity\Domain;

final class UserMustHaveARole extends \DomainException
{
    public static function none(): self
    {
        return new self(\sprintf('A user must have at least one role (%s).', implode(', ', array_map(
            static fn (Role $role): string => $role->value,
            Role::cases(),
        ))));
    }
}
