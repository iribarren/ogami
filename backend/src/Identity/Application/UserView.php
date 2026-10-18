<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Role;
use App\Identity\Domain\User;

/**
 * A user's public data: never the password hash.
 */
final readonly class UserView
{
    /**
     * @param list<string> $roles  Role values, e.g. "SOLO_PLAYER"
     * @param string       $status "active" or "disabled"
     */
    public function __construct(
        public string $id,
        public string $email,
        public array $roles,
        public string $status,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromUser(User $user): self
    {
        return new self(
            $user->id()->toString(),
            $user->email()->toString(),
            array_map(static fn (Role $role): string => $role->value, $user->roles()),
            $user->status()->value,
            $user->createdAt(),
        );
    }
}
