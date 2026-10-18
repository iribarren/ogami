<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * A stored row holds a role that Role does not know (a role removed from the code,
 * or a hand edited row). It must not load silently, and it is not a ValueError.
 */
final class UnknownStoredRole extends \DomainException
{
    public static function named(string $value): self
    {
        return new self(\sprintf('A stored role "%s" is not a known role (%s).', $value, implode(', ', array_map(
            static fn (Role $role): string => $role->value,
            Role::cases(),
        ))));
    }
}
