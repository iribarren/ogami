<?php

declare(strict_types=1);

namespace App\Identity\Domain;

final class InvitationMustHaveARole extends \DomainException
{
    public static function none(): self
    {
        return new self(\sprintf('An invitation must give at least one role (%s).', implode(', ', array_map(
            static fn (Role $role): string => $role->value,
            Role::cases(),
        ))));
    }
}
