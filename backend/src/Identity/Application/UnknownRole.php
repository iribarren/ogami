<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Role;

final class UnknownRole extends \InvalidArgumentException
{
    public static function named(string $value): self
    {
        return new self(\sprintf('"%s" is not a role; expected one of %s.', $value, implode(', ', array_map(
            static fn (Role $role): string => $role->value,
            Role::cases(),
        ))));
    }
}
