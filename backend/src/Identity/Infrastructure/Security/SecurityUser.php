<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Adapts the domain User to Symfony Security. Identified by email; each domain
 * role becomes "ROLE_<VALUE>" (e.g. ROLE_SOLO_PLAYER). Roles are independent:
 * no role hierarchy is configured (ADR 0006).
 */
final readonly class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    /**
     * @param non-empty-string $email
     * @param list<string>     $roles Symfony role names
     */
    private function __construct(
        private string $id,
        private string $email,
        private string $passwordHash,
        private array $roles,
    ) {
    }

    public static function fromUser(User $user): self
    {
        $email = $user->email()->toString();
        \assert('' !== $email);

        return new self(
            $user->id()->toString(),
            $email,
            $user->passwordHash(),
            array_map(static fn (Role $role): string => 'ROLE_'.$role->value, $user->roles()),
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }
}
